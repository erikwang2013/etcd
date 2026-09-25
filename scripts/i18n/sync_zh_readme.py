#!/usr/bin/env python3
"""Regenerate docs/i18n/README.zh.md from the root README.md.

The root README *is* the Chinese edition; the copy under docs/i18n/ exists so
every language sits in one folder with the same shape (switcher, relative
paths). Deriving it instead of maintaining a copy means it cannot drift.

    python3 scripts/i18n/sync_zh_readme.py            # rewrite the copy
    python3 scripts/i18n/sync_zh_readme.py --check    # fail if out of date
"""
from __future__ import annotations

import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
SOURCE = os.path.join(ROOT, "README.md")
TARGET = os.path.join(ROOT, "docs", "i18n", "README.zh.md")

SWITCHER = """<p align="center">
  <a href="../../README.md">简体中文</a> ·
  <a href="README.en.md">English</a> ·
  <a href="README.ko.md">한국어</a> ·
  <a href="README.ru.md">Русский</a> ·
  <a href="README.de.md">Deutsch</a> ·
  <a href="README.fr.md">Français</a> ·
  <a href="README.es.md">Español</a> ·
  <a href="README.pt.md">Português</a> ·
  <a href="README.hi.md">हिन्दी</a> ·
  <a href="README.ar.md">العربية</a> ·
  <a href="README.bn.md">বাংলা</a> ·
  <a href="README.id.md">Bahasa Indonesia</a> ·
  <a href="README.ja.md">日本語</a>
</p>"""

# ./docs/<file> from the repo root, seen from docs/i18n/
REMAP = {
    "architecture.svg": "diagrams/zh/architecture.svg",
    "features.svg": "diagrams/zh/features.svg",
    "lifecycle.svg": "diagrams/zh/lifecycle.svg",
}
PATH = re.compile(r"\./docs/([A-Za-z0-9._-]+)")


def render() -> str:
    text = open(SOURCE, encoding="utf-8").read()

    def repl(match: re.Match) -> str:
        return REMAP.get(match.group(1), "../" + match.group(1))

    # Only outside code fences: the project tree describes the repo, it is not
    # a set of links.
    parts = re.split(r"(^```.*?^```)", text, flags=re.S | re.M)
    body = "".join(p if p.startswith("```") else PATH.sub(repl, p) for p in parts)

    lines = body.split("\n")
    if not lines[0].startswith("# "):
        raise SystemExit("README.md does not start with an H1")
    return "\n".join([lines[0], "", SWITCHER, ""] + lines[1:])


def main(argv: list[str]) -> int:
    want = render()
    check = "--check" in argv
    if check:
        current = open(TARGET, encoding="utf-8").read() if os.path.exists(TARGET) else ""
        if current != want:
            print("docs/i18n/README.zh.md is out of date — run "
                  "python3 scripts/i18n/sync_zh_readme.py", file=sys.stderr)
            return 1
        print("OK — docs/i18n/README.zh.md matches README.md.")
        return 0
    with open(TARGET, "w", encoding="utf-8") as fh:
        fh.write(want)
    print(f"wrote {os.path.relpath(TARGET, ROOT)}")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
