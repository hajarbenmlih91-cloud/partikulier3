#!/usr/bin/env python3
"""Bounded synthetic load characterization for the disposable CI WordPress service.

This is deliberately locked to the GitHub Actions runner's loopback mapping. It
will not accept an arbitrary URL/host and never contacts a staging or production
service. Request bodies and credentials are never printed.
"""
from __future__ import annotations

import argparse
import base64
import concurrent.futures
from concurrent.futures import FIRST_COMPLETED, wait
import hashlib
import hmac
import http.client
import json
import math
import os
import statistics
import sys
import time
from typing import Any

ROUTE = "/wp-json/partikulier/v1/automation-event"
SIGNING_PATH = "/partikulier/v1/automation-event"
ALLOWED_HOST = "127.0.0.1"
ALLOWED_PORT = 8080


def percentile(values: list[float], fraction: float) -> float:
    if not values:
        return 0.0
    ordered = sorted(values)
    return ordered[max(0, math.ceil(fraction * len(ordered)) - 1)]


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--host", default=ALLOWED_HOST)
    parser.add_argument("--port", type=int, default=ALLOWED_PORT)
    parser.add_argument("--rps", type=int, default=10)
    parser.add_argument("--duration", type=int, default=30)
    parser.add_argument("--p95-budget-ms", type=int, default=1000)
    args = parser.parse_args()

    if args.host != ALLOWED_HOST or args.port != ALLOWED_PORT:
        parser.error("target must be the disposable WordPress loopback mapping")
    if not (1 <= args.rps <= 10 and 1 <= args.duration <= 60):
        parser.error("bounded limits are 1..10 requests/s and 1..60 seconds")
    secret = os.environ.get("PK_EPHEMERAL_SECRET", "")
    run_id = os.environ.get("GITHUB_RUN_ID", "")
    if not secret or not run_id.isdigit():
        parser.error("ephemeral secret and numeric CI run id are required")
    try:
        key = base64.b64decode(secret, validate=True)
    except Exception:
        parser.error("ephemeral HMAC key must be base64")
    if len(key) < 32:
        parser.error("ephemeral HMAC key must contain at least 32 decoded bytes")

    total = args.rps * args.duration
    outcomes: list[dict[str, Any]] = []

    def send(sequence: int) -> dict[str, Any]:
        event_id = f"n8n-load-{run_id}-{sequence}"
        body = json.dumps(
            {
                "event_id": event_id,
                "event_type": "whatsapp_status",
                "source": "n8n",
                "payload": {"synthetic_probe": True, "sequence": sequence},
            },
            separators=(",", ":"),
            ensure_ascii=False,
        )
        timestamp = str(int(time.time()))
        canonical = f"POST\n{SIGNING_PATH}\n{timestamp}\n{body}".encode("utf-8")
        signature = "sha256=" + hmac.new(key, canonical, hashlib.sha256).hexdigest()
        headers = {
            "Host": "wordpress",
            "Content-Type": "application/json",
            "X-Partikulier-Automation": secret,
            "X-Partikulier-Timestamp": timestamp,
            "X-Partikulier-Key-Id": "N",
            "X-Partikulier-Signature": signature,
        }
        started = time.perf_counter()
        try:
            connection = http.client.HTTPConnection(ALLOWED_HOST, ALLOWED_PORT, timeout=5)
            connection.request("POST", ROUTE, body=body.encode("utf-8"), headers=headers)
            response = connection.getresponse()
            raw = response.read(4096)
            elapsed = (time.perf_counter() - started) * 1000
            try:
                data = json.loads(raw.decode("utf-8"))
            except Exception:
                data = {}
            result = {
                "http": response.status,
                "accepted": data.get("accepted") is True,
                "duplicate": data.get("duplicate") is True,
                "latency_ms": elapsed,
            }
            connection.close()
            return result
        except Exception as error:  # only emit the exception class, never request or response data
            return {
                "http": 0,
                "accepted": False,
                "duplicate": False,
                "latency_ms": (time.perf_counter() - started) * 1000,
                "error_type": type(error).__name__,
            }

    # Never allow catch-up bursts: launches are spaced by at least 1/RPS,
    # and in-flight requests are bounded by the same RPS ceiling.
    workers = min(args.rps, 10)
    started = time.monotonic()
    deadline = started + args.duration
    interval = 1 / args.rps
    futures: set[concurrent.futures.Future[dict[str, Any]]] = set()
    outcomes: list[dict[str, Any]] = []
    last_launch = 0.0
    with concurrent.futures.ThreadPoolExecutor(max_workers=workers) as pool:
        for sequence in range(total):
            if last_launch:
                delay = interval - (time.monotonic() - last_launch)
                if delay > 0:
                    time.sleep(delay)
            if len(futures) >= workers:
                done, futures = wait(futures, return_when=FIRST_COMPLETED)
                outcomes.extend(future.result() for future in done)
            if time.monotonic() >= deadline:
                break
            last_launch = time.monotonic()
            futures.add(pool.submit(send, sequence))
        outcomes.extend(future.result() for future in futures)
    elapsed_seconds = time.monotonic() - started

    latencies = [float(row["latency_ms"]) for row in outcomes]
    accepted = sum(row["http"] == 200 and row["accepted"] and not row["duplicate"] for row in outcomes)
    scheduled = len(outcomes)
    failures = total - accepted
    measured_seconds = max(float(args.duration), elapsed_seconds)
    achieved_rps = scheduled / measured_seconds if measured_seconds else 0
    result = {
        "target": "127.0.0.1:8080 (disposable CI WordPress only)",
        "target_rps": args.rps,
        "duration_seconds": args.duration,
        "requests": total,
        "scheduled": scheduled,
        "accepted_unique": accepted,
        "failures": failures,
        "elapsed_seconds": round(elapsed_seconds, 3),
        "achieved_rps": round(achieved_rps, 2),
        "p50_ms": round(statistics.median(latencies), 2) if latencies else 0,
        "p95_ms": round(percentile(latencies, 0.95), 2),
        "max_ms": round(max(latencies), 2) if latencies else 0,
        "error_types": sorted({str(row["error_type"]) for row in outcomes if row.get("error_type")}),
    }
    print(json.dumps(result, separators=(",", ":")), flush=True)
    enough_throughput = achieved_rps >= args.rps * 0.95
    return 0 if scheduled == total and failures == 0 and enough_throughput and result["p95_ms"] <= args.p95_budget_ms else 1


if __name__ == "__main__":
    sys.exit(main())
