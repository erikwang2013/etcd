#!/usr/bin/env python3
"""Verify the translated READMEs and catalogs are complete and internally sound.

    python3 scripts/i18n/check_translations.py

Checks, per language:
  * docs/i18n/README.<lang>.md exists
  * it carries the full 13-entry language switcher
  * every relative link / image path in it resolves on disk
  * its diagram images point at its own diagrams/<lang>/ folder
  * its catalog exists and covers every key of the zh source of truth
Exits non-zero on the first category that fails.
"""
from __future__ import annotations

import json
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
I18N = os.path.join(ROOT, "docs", "i18n")
CATALOG = os.path.join(ROOT, "scripts", "i18n", "catalog")

LANGS = ["zh", "en", "ko", "ru", "de", "fr", "es", "pt", "hi", "ar", "bn", "id", "ja"]
NAMES = ["简体中文", "English", "한국어", "Русский", "Deutsch", "Français", "Español",
         "Português", "हिन्दी", "العربية", "বাংলা", "Bahasa Indonesia", "日本語"]
DIAGRAMS = ("architecture", "features", "lifecycle")

LINK = re.compile(r"!?\[[^\]]*\]\(([^)]+)\)")          # markdown links and images
HTML_SRC = re.compile(r'(?:src|href)="([^"]+)"')        # html img/a
FENCE = re.compile(r"^```.*?^```", re.S | re.M)         # fenced code blocks


def prose(text: str) -> str:
    """Drop fenced code blocks: paths inside them are samples, not links."""
    return FENCE.sub("", text)


def fail(msg: str, errors: list[str]) -> None:
    errors.append(msg)


def check_lang(lang: str, errors: list[str]) -> None:
    rel = f"docs/i18n/README.{lang}.md"
    path = os.path.join(ROOT, rel)
    if not os.path.exists(path):
        return fail(f"{rel}: missing", errors)

    text = open(path, encoding="utf-8").read()
    body = prose(text)

    # switcher: every language name present, and linked to the right target
    missing = [n for n in NAMES if n not in text]
    if missing:
        fail(f"{rel}: switcher is missing {missing}", errors)
    anchor = text.count('<a href="../../README.md">简体中文</a>')
    if anchor != 1:
        fail(f"{rel}: switcher block found {anchor} times, expected exactly 1", errors)
    for other in LANGS:
        target = "../../README.md" if other == "zh" else f"README.{other}.md"
        if target not in text:
            fail(f"{rel}: switcher has no link to {target}", errors)

    # every relative path must resolve
    for raw in LINK.findall(body) + HTML_SRC.findall(body):
        target = raw.split("#")[0].strip()
        if not target or target.startswith(("http://", "https://", "mailto:", "data:")):
            continue
        if not os.path.exists(os.path.normpath(os.path.join(I18N, target))):
            fail(f"{rel}: broken path -> {raw}", errors)

    # its own diagrams, in its own folder
    for name in DIAGRAMS:
        expected = f"diagrams/{lang}/{name}.svg"
        if expected not in text:
            fail(f"{rel}: does not reference {expected}", errors)
        if not os.path.exists(os.path.join(I18N, expected)):
            fail(f"{rel}: {expected} was never built", errors)

    # catalog coverage
    cat = os.path.join(CATALOG, f"{lang}.json")
    if lang == "zh":
        return
    if not os.path.exists(cat):
        return fail(f"scripts/i18n/catalog/{lang}.json: missing", errors)
    data = json.load(open(cat, encoding="utf-8"))
    source = json.load(open(os.path.join(CATALOG, "zh.json"), encoding="utf-8"))
    gaps = sorted(set(source) - set(data))
    if gaps:
        fail(f"scripts/i18n/catalog/{lang}.json: {len(gaps)} untranslated key(s): {gaps[:5]}", errors)
    empty = sorted(k for k, v in data.items() if not str(v).strip())
    if empty:
        fail(f"scripts/i18n/catalog/{lang}.json: {len(empty)} empty value(s): {empty[:5]}", errors)


def main() -> int:
    errors: list[str] = []

    # Every catalog must also survive the diagram layout check, otherwise the
    # committed SVGs would be stale or truncated.
    sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
    import build_diagrams as bd

    for lang in LANGS:
        before = len(bd.PROBLEMS)
        bd.build(lang)
        for prob in bd.PROBLEMS[before:]:
            fail(f"diagram layout: {prob}", errors)

    for lang in LANGS:
        check_lang(lang, errors)

    # the root README must point at every translation, exactly once
    root = open(os.path.join(ROOT, "README.md"), encoding="utf-8").read()
    for lang in LANGS:
        if lang == "zh":
            continue
        marker = f"docs/i18n/README.{lang}.md"
        if root.count(marker) != 1:
            fail(f"README.md: expected exactly one link to {marker}, found {root.count(marker)}", errors)
    if errors:
        print(f"{len(errors)} problem(s):", file=sys.stderr)
        for e in errors:
            print("  " + e, file=sys.stderr)
        return 1
    print(f"OK — {len(LANGS)} languages: READMEs, switchers, links and catalogs all consistent.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
