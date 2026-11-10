#!/usr/bin/env python3
"""
sonde-capacite-uat.py — Sonde de capacité HTTP ponctuelle hors harnais d'audit.

Objet :
  staging-charge.php agrège uniquement les classes HTTP (2xx/3xx/4xx/5xx) sans
  publier le code exact des échecs (500, 502, 503, 504, 429…) ni les en-têtes
  de l'intermédiaire qui refuse. Cette sonde open-loop hors harnais mesure :
    1. Les paliers 10, 20, 50 et 100 req/s sur UAT ;
    2. La ventilation exacte par code HTTP (et non par classe) ;
    3. La latence p50/p95 par code et les en-têtes d'intermédiaire (Server,
       x-hcdn-*, x-litespeed-cache, x-partikulier-cache, retry-after) des
       réponses non-200 afin d'identifier l'auteur du refus.

Usage :
  python3 docs/audit/sonde-capacite-uat.py https://darkcyan-yak-151002.hostingersite.com
"""

import asyncio
import collections
import datetime
import socket
import sys
import time
import aiohttp

PATHS = ['/fr/annonces/', '/fr/', '/fr/annonces/?es_city=casablanca']
STEPS = [
    (10, 5),
    (20, 5),
    (50, 5),
    (100, 15),
]
INTERESTING_HEADERS = [
    'server', 'platform', 'panel', 'via', 'cf-ray', 'x-cache',
    'x-turbo-charged-by', 'x-hcdn-request-id', 'x-hcdn-cache-status',
    'x-hcdn-upstream-rt', 'x-litespeed-cache', 'x-partikulier-cache',
    'retry-after', 'cache-control', 'content-type',
]


def pct(sorted_vals, p):
    if not sorted_vals:
        return 0.0
    idx = max(0, min(len(sorted_vals) - 1, int((p / 100.0) * len(sorted_vals))))
    return sorted_vals[idx]


async def fire_one(session, url, results, samples):
    t0 = time.perf_counter()
    try:
        async with session.get(url, allow_redirects=False) as resp:
            body = await resp.read()
            dt_ms = (time.perf_counter() - t0) * 1000.0
            code = resp.status
            results.append((code, dt_ms, len(body)))
            if code != 200 and len(samples[code]) < 2:
                hdrs = {k.lower(): v for k, v in resp.headers.items() if k.lower() in INTERESTING_HEADERS}
                snippet = body[:200].decode('utf-8', 'replace').replace('\n', ' ').strip()
                samples[code].append({'url': url, 'ms': round(dt_ms, 1), 'headers': hdrs, 'snippet': snippet})
    except Exception as exc:
        dt_ms = (time.perf_counter() - t0) * 1000.0
        key = f'ERR:{type(exc).__name__}'
        results.append((key, dt_ms, 0))
        if len(samples[key]) < 2:
            samples[key].append({'url': url, 'ms': round(dt_ms, 1), 'error': str(exc)})


async def run_step(base, rps, duration):
    total_planned = rps * duration
    results = []
    samples = collections.defaultdict(list)
    connector = aiohttp.TCPConnector(limit=256, ttl_dns_cache=60)
    timeout = aiohttp.ClientTimeout(total=20, connect=10)
    headers = {'User-Agent': 'Partikulier-Capacity-Probe/1.0 (+diagnostic)'}

    async with aiohttp.ClientSession(connector=connector, timeout=timeout, headers=headers) as session:
        tasks = []
        t_start = time.perf_counter()
        interval = 1.0 / rps
        for i in range(total_planned):
            target = t_start + i * interval
            now = time.perf_counter()
            if target > now:
                await asyncio.sleep(target - now)
            url = base + PATHS[i % len(PATHS)]
            tasks.append(asyncio.create_task(fire_one(session, url, results, samples)))
        await asyncio.gather(*tasks)
        elapsed = time.perf_counter() - t_start

    by_code = collections.Counter(c for c, _, _ in results)
    lat_all = sorted(ms for _, ms, _ in results)
    lat_by_code = collections.defaultdict(list)
    for c, ms, _ in results:
        lat_by_code[c].append(ms)
    for c in lat_by_code:
        lat_by_code[c].sort()

    return {
        'rps_target': rps,
        'duration_s': duration,
        'elapsed_s': elapsed,
        'planned': total_planned,
        'completed': len(results),
        'actual_rps': len(results) / max(0.001, elapsed),
        'by_code': dict(by_code),
        'p50_all': pct(lat_all, 50),
        'p95_all': pct(lat_all, 95),
        'p99_all': pct(lat_all, 99),
        'lat_by_code': {
            c: (pct(v, 50), pct(v, 95), min(v), max(v))
            for c, v in lat_by_code.items()
        },
        'samples': dict(samples),
    }


async def main():
    if len(sys.argv) < 2:
        print('Usage: sonde-capacite-uat.py <https://uat-host>', file=sys.stderr)
        sys.exit(2)
    base = sys.argv[1].rstrip('/')
    host = base.split('://', 1)[1].split('/', 1)[0]
    now_utc = datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
    print(f'Date UTC : {now_utc}')
    print(f'Cible    : {base}')
    try:
        cname, aliases, addrs = socket.gethostbyname_ex(host)
        print(f'DNS      : cname={cname} aliases={aliases} addrs={addrs}')
    except Exception as e:
        print(f'DNS err  : {e}')

    for rps, dur in STEPS:
        res = await run_step(base, rps, dur)
        print(f"\n=== PALIER {res['rps_target']} req/s pendant {res['duration_s']} s "
              f"({res['completed']}/{res['planned']} reqs, débit réel {res['actual_rps']:.1f} req/s, "
              f"p50={res['p50_all']:.0f}ms p95={res['p95_all']:.0f}ms p99={res['p99_all']:.0f}ms) ===")
        for code, count in sorted(res['by_code'].items(), key=lambda x: str(x[0])):
            p50, p95, vmin, vmax = res['lat_by_code'][code]
            print(f"  Code {str(code):18s}: {count:4d} reqs | p50={p50:6.1f}ms p95={p95:6.1f}ms min={vmin:5.1f}ms max={vmax:6.1f}ms")
        for code, slist in res['samples'].items():
            for idx, s in enumerate(slist, 1):
                print(f"  [Échantillon {code} #{idx}] {s}")
        await asyncio.sleep(2.0)


if __name__ == '__main__':
    asyncio.run(main())
