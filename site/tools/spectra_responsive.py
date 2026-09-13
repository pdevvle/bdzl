#!/usr/bin/env python3
"""Move root-level style into responsiveControls for Spectra blocks.

spectra-blocks 1.0.x strips style.spacing / typography / border / shadow from
root attributes at render_block_data and generates per-breakpoint CSS from
attrs.responsiveControls[lg|md|sm] instead. style.color stays inline, so it is
left at the root. Also assigns a stable spectraId per block: without one the
plugin mints a fresh uuid every render and disables its own CSS cache.

    python3 site/tools/spectra_responsive.py in.html out.html [id-prefix]
"""
import json
import re
import sys

SRC, DST = sys.argv[1], sys.argv[2]
PREFIX = sys.argv[3] if len(sys.argv) > 3 else "hb"

RESPONSIVE_STYLE_KEYS = ("spacing", "typography", "border", "shadow")
RESPONSIVE_ATTRS = ("minHeight", "minWidth", "maxWidth", "maxHeight", "width", "height")

blocks = re.compile(r"<!--\s+(/?)wp:([a-z0-9/-]+)(\s+(\{.*?\}))?\s*(/?)-->", re.S)
counter = [0]


def num(v, unit):
    m = re.fullmatch(r"(\d+(?:\.\d+)?)" + unit, str(v))
    return float(m.group(1)) if m else None


def shrink_padding(pad):
    out = {}
    for side, val in pad.items():
        n = num(val, "px")
        if n is None:
            out[side] = val
        elif side in ("top", "bottom"):
            out[side] = f"{int(round(n * 0.68))}px" if n >= 40 else val
        else:
            out[side] = "20px" if n >= 24 else val
    return out


def mobile_style(style):
    """Derive an sm override from the lg style, or None when nothing changes."""
    sm = {}
    fs = style.get("typography", {}).get("fontSize")
    n = num(fs, "rem") if fs else None
    if n and n >= 2.0:
        sm.setdefault("typography", {})["fontSize"] = f"{round(n * 0.7, 2)}rem"
    pad = style.get("spacing", {}).get("padding")
    if pad:
        shrunk = shrink_padding(pad)
        if shrunk != pad:
            sm.setdefault("spacing", {})["padding"] = shrunk
    return sm or None


def convert(attrs):
    style = attrs.get("style", {})
    lg_style = {k: style.pop(k) for k in RESPONSIVE_STYLE_KEYS if k in style}
    sm_style = mobile_style(lg_style) if lg_style else None

    lg = {"style": lg_style} if lg_style else {}
    for attr in RESPONSIVE_ATTRS:
        if attr in attrs:
            lg[attr] = attrs.pop(attr)

    if not style:
        attrs.pop("style", None)

    if lg:
        rc = attrs.setdefault("responsiveControls", {})
        rc.setdefault("lg", {}).update(lg)
        if sm_style:
            rc.setdefault("sm", {})["style"] = sm_style
    return attrs


def repl(m):
    closing, name, _, js, selfclose = m.groups()
    if closing or not (name.startswith("spectra/") or name == "image"):
        return m.group(0)
    attrs = json.loads(js) if js else {}
    if name.startswith("spectra/"):
        attrs = convert(attrs)
        if "spectraId" not in attrs:
            counter[0] += 1
            attrs["spectraId"] = f"spectra-{PREFIX}-{counter[0]:04d}"
    elif name == "image":
        # core/image is in the extension's supported_blocks list, so its
        # border radius is stripped from the root too.
        style = attrs.get("style", {})
        if "border" in style:
            border = style.pop("border")
            attrs.setdefault("responsiveControls", {}).setdefault("lg", {})["style"] = {"border": border}
            if not style:
                attrs.pop("style", None)
    body = json.dumps(attrs, ensure_ascii=False, separators=(",", ":")) if attrs else ""
    return f"<!-- {'/' if closing else ''}wp:{name}{' ' + body if body else ''} {'/' if selfclose else ''}-->"


out = blocks.sub(repl, open(SRC).read())
open(DST, "w").write(out)

# Validate: JSON parses, blocks balance.
stack, errors = [], 0
for m in blocks.finditer(out):
    closing, name, _, js, selfclose = m.groups()
    if js:
        try:
            json.loads(js)
        except Exception as exc:
            errors += 1
            print(f"BAD JSON in {name}: {exc}")
    if selfclose:
        continue
    if closing:
        if not stack or stack[-1] != name:
            errors += 1
            print(f"MISMATCHED close: {name}")
        else:
            stack.pop()
    else:
        stack.append(name)

print(f"blocks rewritten: {counter[0]}  chars: {len(out)}  unclosed: {stack}  errors: {errors}")
sys.exit(1 if (errors or stack) else 0)
