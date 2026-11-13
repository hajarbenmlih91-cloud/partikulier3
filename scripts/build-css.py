#!/usr/bin/env python3
"""Deterministically build and verify style.min.css and critical.min.css from style.css."""
from __future__ import annotations

import argparse
from pathlib import Path
import re
import sys

ROOT = Path(__file__).resolve().parent.parent
CSS_DIR = ROOT / "theme" / "partikulier" / "assets" / "css"
SRC_CSS = CSS_DIR / "style.css"
MIN_CSS = CSS_DIR / "style.min.css"
CRIT_CSS = CSS_DIR / "critical.min.css"

CRITICAL_GUARD = (
    ".pk-find-panel,.pk-find-backdrop{display:none}"
    "[hidden],.pk-lang-gate[hidden],.pk-lang-menu[hidden],.pk-mobile-menu[hidden],"
    ".pk-place-suggestions[hidden],#es-authentication-popup.mfp-hide{display:none!important}"
    ".pk-find-open{position:absolute;width:1px;height:1px;overflow:hidden;"
    "clip:rect(0 0 0 0);clip-path:inset(50%)}"
)

CRITICAL_CLASSES = frozenset({
    "home",
    "is-current",
    "pk-appbar",
    "pk-appbar-item",
    "pk-appbar-plus",
    "pk-appbar-publish",
    "pk-badge-a-louer",
    "pk-btn",
    "pk-btn-light",
    "pk-btn-primary",
    "pk-btn-sm",
    "pk-card",
    "pk-card-action",
    "pk-card-actions",
    "pk-card-badge",
    "pk-card-body",
    "pk-card-city",
    "pk-card-cta",
    "pk-card-dot",
    "pk-card-fact",
    "pk-card-fact-icon",
    "pk-card-fact-icon--bed",
    "pk-card-facts",
    "pk-card-foot",
    "pk-card-media",
    "pk-card-media-link",
    "pk-card-peek",
    "pk-card-price",
    "pk-card-price-note",
    "pk-card-property",
    "pk-card-title",
    "pk-card-wishlist",
    "pk-container",
    "pk-cookie-actions",
    "pk-current",
    "pk-editorial-cards",
    "pk-editorial-heading",
    "pk-editorial-hero",
    "pk-editorial-hero__copy",
    "pk-editorial-hero__inner",
    "pk-editorial-hero__intro",
    "pk-editorial-kicker",
    "pk-editorial-link",
    "pk-editorial-more",
    "pk-editorial-search",
    "pk-editorial-section",
    "pk-editorial-section--featured",
    "pk-editorial-section--tint",
    "pk-editorial-type",
    "pk-editorial-type__icon",
    "pk-editorial-types",
    "pk-filter-chip",
    "pk-filter-chip-chevron",
    "pk-filter-chip-icon",
    "pk-filter-chips",
    "pk-find",
    "pk-find-card",
    "pk-find-city",
    "pk-find-field",
    "pk-find-filter",
    "pk-find-label",
    "pk-find-need",
    "pk-find-open",
    "pk-find-submit",
    "pk-find-tab",
    "pk-find-tab-input",
    "pk-find-tabs",
    "pk-find-type-backdrop",
    "pk-find-type-btn",
    "pk-find-type-current",
    "pk-find-type-kicker",
    "pk-find-type-menu",
    "pk-find-type-opt",
    "pk-find-type-ui",
    "pk-find-typefield",
    "pk-flag",
    "pk-header-actions",
    "pk-header-brand",
    "pk-header-cta",
    "pk-header-icon",
    "pk-header-inner",
    "pk-header-search",
    "pk-hero-accent",
    "pk-hero-trust",
    "pk-home-chips",
    "pk-lang",
    "pk-lang-abbr",
    "pk-lang-code",
    "pk-lang-flag",
    "pk-lang-gate",
    "pk-lang-gate-actions",
    "pk-lang-gate-card",
    "pk-lang-gate-close",
    "pk-lang-gate-kicker",
    "pk-lang-gate-note",
    "pk-lang-gate-sub",
    "pk-lang-gate-title",
    "pk-lang-go",
    "pk-lang-menu",
    "pk-lang-name",
    "pk-lang-names",
    "pk-lang-option",
    "pk-lang-toggle",
    "pk-logo-img",
    "pk-logo-text",
    "pk-main",
    "pk-main-nav",
    "pk-menu",
    "pk-menu-item",
    "pk-menu-mobile",
    "pk-mobile-menu",
    "pk-mobile-sticky-cta",
    "pk-nav-toggle",
    "pk-nav-toggle-bar",
    "pk-place-autocomplete-wrap",
    "pk-place-input",
    "pk-place-suggestions",
    "pk-price-num",
    "pk-scroll-actions",
    "pk-scroll-hint",
    "pk-scroll-hint-arrow",
    "pk-search",
    "pk-search-bar",
    "pk-search-hero",
    "pk-site-header",
    "pk-skip-link",
    "pk-suggest",
    "pk-topbar",
    "pk-topbar-contact",
    "pk-topbar-inner",
    "pk-topbar-promo",
    "screen-reader-text",
})

BASE_TAGS = frozenset({
    "*", "*::before", "*::after", ":root", "html", "body", "img", "svg", "a", "a:hover",
    "h1", "h2", "h3", "h4", "p", "ul", "ol", "button", "input", "select", "textarea",
    ":focus-visible", "em",
})


def strip_css_comments(text: str) -> str:
    out: list[str] = []
    i = 0
    n = len(text)
    in_str: str | None = None
    while i < n:
        ch = text[i]
        if in_str:
            out.append(ch)
            if ch == "\\" and i + 1 < n:
                out.append(text[i + 1])
                i += 2
                continue
            if ch == in_str:
                in_str = None
            i += 1
            continue
        if ch in ('"', "'"):
            in_str = ch
            out.append(ch)
            i += 1
            continue
        if ch == "/" and i + 1 < n and text[i + 1] == "*":
            end = text.find("*/", i + 2)
            i = n if end == -1 else end + 2
            continue
        out.append(ch)
        i += 1
    return "".join(out)


def minify_css(text: str) -> str:
    """Deterministic CSS minifier preserving quoted strings, descendant pseudo-classes, and media/container queries."""
    text = strip_css_comments(text)
    out: list[str] = []
    i = 0
    n = len(text)
    in_str: str | None = None
    at_block_stack: list[bool] = []
    paren = 0
    prev_was_space = False

    while i < n:
        ch = text[i]
        if in_str:
            out.append(ch)
            if ch == "\\" and i + 1 < n:
                out.append(text[i + 1])
                i += 2
                continue
            if ch == in_str:
                in_str = None
            i += 1
            continue
        if ch in ('"', "'"):
            if prev_was_space and out and out[-1] not in "{}:;,([":
                out.append(" ")
            prev_was_space = False
            in_str = ch
            out.append(ch)
            i += 1
            continue
        if ch.isspace():
            prev_was_space = True
            i += 1
            continue
        if ch == "{":
            tail = "".join(out[-64:])
            is_nested = bool(
                re.search(r"@(?:media|supports|layer|container|document|keyframes)\b[^{};]*$", tail)
            )
            at_block_stack.append(is_nested)
            out.append("{")
            prev_was_space = False
            i += 1
            continue
        if ch == "}":
            if at_block_stack:
                at_block_stack.pop()
            if out and out[-1] == ";":
                out.pop()
            out.append("}")
            prev_was_space = False
            i += 1
            continue
        if ch in (";", ","):
            out.append(ch)
            prev_was_space = False
            i += 1
            continue
        if ch == ":":
            in_decl = bool(at_block_stack) and not at_block_stack[-1] and paren == 0
            if not in_decl and prev_was_space and out and out[-1] not in "{};,(:":
                out.append(" ")
            out.append(":")
            prev_was_space = False
            i += 1
            continue
        if ch == "(":
            paren += 1
            if prev_was_space and out and out[-1] not in "{};,(:":
                out.append(" ")
            out.append("(")
            prev_was_space = False
            i += 1
            continue
        if ch == ")":
            paren = max(0, paren - 1)
            out.append(")")
            prev_was_space = False
            i += 1
            continue
        if prev_was_space and out and out[-1] not in "{};,(:":
            out.append(" ")
        prev_was_space = False
        out.append(ch)
        i += 1

    return "".join(out).strip()


def split_top_level_blocks(css: str) -> list[tuple[str, str]]:
    """Split CSS into top-level (prelude, '{...}') blocks."""
    blocks: list[tuple[str, str]] = []
    i = 0
    n = len(css)
    in_str: str | None = None
    start = 0
    while i < n:
        ch = css[i]
        if in_str:
            if ch == "\\" and i + 1 < n:
                i += 2
                continue
            if ch == in_str:
                in_str = None
            i += 1
            continue
        if ch in ('"', "'"):
            in_str = ch
            i += 1
            continue
        if ch == "{":
            prelude = css[start:i].strip()
            depth = 1
            j = i + 1
            inner_str: str | None = None
            while j < n and depth > 0:
                c2 = css[j]
                if inner_str:
                    if c2 == "\\" and j + 1 < n:
                        j += 2
                        continue
                    if c2 == inner_str:
                        inner_str = None
                elif c2 in ('"', "'"):
                    inner_str = c2
                elif c2 == "{":
                    depth += 1
                elif c2 == "}":
                    depth -= 1
                j += 1
            blocks.append((prelude, css[i:j]))
            start = j
            i = j
            continue
        if ch == ";" and not css[start:i].strip():
            start = i + 1
        i += 1
    return blocks


def keep_critical_selector_part(part: str) -> bool:
    if re.findall(r"#([a-zA-Z_-][a-zA-Z0-9_-]*)", part):
        return False
    cls = re.findall(r"\.([a-zA-Z_][a-zA-Z0-9_-]*)", part)
    if cls:
        return all(c in CRITICAL_CLASSES for c in cls)
    base = re.sub(r":+[a-zA-Z-]+(?:\([^)]*\))?", "", part).strip()
    if part in BASE_TAGS or base in BASE_TAGS:
        return True
    if re.search(r'button\[type="submit"\]', part):
        return True
    if "[data-pk-rooms]" in part or ":focus-visible" in part:
        return True
    if part.startswith("html"):
        return not any(w in part for w in ("footer", "single", "archive", "dashboard", "submit"))
    return False


def filter_critical_blocks(blocks: list[tuple[str, str]]) -> str:
    kept: list[str] = []
    for prelude, body in blocks:
        if prelude.startswith(("@font-face", "@keyframes")):
            kept.append(f"{prelude}{body}")
        elif prelude.startswith(("@media", "@supports", "@container")):
            inner_kept = filter_critical_blocks(split_top_level_blocks(body[1:-1]))
            if inner_kept:
                kept.append(f"{prelude}{{{inner_kept}}}")
        elif prelude.startswith("@"):
            kept.append(f"{prelude}{body}")
        else:
            parts = [p.strip() for p in prelude.split(",") if p.strip()]
            kept_parts = [p for p in parts if keep_critical_selector_part(p)]
            if kept_parts:
                kept.append(f"{','.join(kept_parts)}{body}")
    return "".join(kept)


def build_outputs() -> tuple[str, str]:
    raw = SRC_CSS.read_text(encoding="utf-8")
    min_css = minify_css(raw)
    crit_body = filter_critical_blocks(split_top_level_blocks(min_css)).replace(
        'url("../fonts/', 'url("__PK_FONTS__/'
    )
    crit_css = crit_body + CRITICAL_GUARD
    return min_css, crit_css


def self_test() -> None:
    sample = '/* note */\nhtml body :focus-visible{color: #fff;}\n.a:not([hidden]){display:block;}\n@media (min-width: 768px) and (max-width: 900px) { .x { width: calc(100% - 1rem); } }\n'
    minified = minify_css(sample)
    assert "html body :focus-visible{color:#fff}" in minified, minified
    assert ".a:not([hidden]){display:block}" in minified, minified
    assert "@media (min-width:768px) and (max-width:900px)" in minified, minified


def main() -> int:
    self_test()
    parser = argparse.ArgumentParser(description="Build or verify style.min.css and critical.min.css.")
    parser.add_argument("--check", action="store_true", help="Fail if committed minified/critical CSS is out of sync.")
    args = parser.parse_args()

    expected_min, expected_crit = build_outputs()

    if args.check:
        mismatches: list[str] = []
        actual_min = MIN_CSS.read_text(encoding="utf-8") if MIN_CSS.is_file() else ""
        actual_crit = CRIT_CSS.read_text(encoding="utf-8") if CRIT_CSS.is_file() else ""
        if actual_min != expected_min:
            mismatches.append(f"{MIN_CSS.relative_to(ROOT)} (actual={len(actual_min)} B, expected={len(expected_min)} B)")
        if actual_crit != expected_crit:
            mismatches.append(f"{CRIT_CSS.relative_to(ROOT)} (actual={len(actual_crit)} B, expected={len(expected_crit)} B)")
        if mismatches:
            print("CSS build check FAILED — out of sync with style.css:", file=sys.stderr)
            for item in mismatches:
                print(f"  - {item}", file=sys.stderr)
            print("Run: python3 scripts/build-css.py", file=sys.stderr)
            return 1
        print(f"CSS sync: PASS ({MIN_CSS.name}={len(expected_min)} B, {CRIT_CSS.name}={len(expected_crit)} B)")
        return 0

    MIN_CSS.write_text(expected_min, encoding="utf-8")
    CRIT_CSS.write_text(expected_crit, encoding="utf-8")
    print(f"Wrote {MIN_CSS.relative_to(ROOT)} ({len(expected_min)} B)")
    print(f"Wrote {CRIT_CSS.relative_to(ROOT)} ({len(expected_crit)} B)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
