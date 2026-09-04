#!/usr/bin/env bash
# Shared helpers for scripts/docs/screenshots.sh. Drives the gstack `browse` daemon
# (https://github.com/garrytan/gstack) against the locally running app.
#
#   source scripts/docs/lib.sh
#   login brooke@example.com password
#   go /clients && shot clients
#
# Every screenshot is a viewport capture (never full-page: browse downscales anything
# over 2000px, and viewport captures are exempt), so set the viewport before the shot.

set -u

B="${BROWSE_BIN:-$HOME/.claude/skills/gstack/browse/dist/browse}"
BASE_URL="${DOCS_BASE_URL:-https://brookeapp.test}"
IMG="${DOCS_IMAGE_DIR:-$(git rev-parse --show-toplevel)/public/docs/images}"
HL_COLOR="#e11d48"

mkdir -p "$IMG"

# Navigate to a path on the app and wait for Livewire to finish loading.
go() {
    "$B" goto "${BASE_URL}$1" >/dev/null
    settle
}

# Wait for the network to go quiet, then a beat for Flux transitions to finish.
settle() {
    "$B" wait --networkidle "${1:-10000}" >/dev/null 2>&1 || true
    sleep "${2:-0.5}"
}

# Viewport in CSS pixels; screenshots are captured at 2x for print.
vp() {
    "$B" viewport "$1" --scale 2 >/dev/null
    sleep 0.3
}

# shot <name> [css-selector]  → public/docs/images/<name>.png
shot() {
    local out="$IMG/$1.png"
    if [ -n "${2:-}" ]; then
        "$B" screenshot "$out" --selector "$2" >/dev/null
    else
        "$B" screenshot "$out" --viewport >/dev/null
    fi
    echo "shot: $out"
}

# Run JavaScript in the page and print the result.
js() {
    "$B" js "$1"
}

# Outline the first control whose text starts with $1; optional $2 is a step number badge.
hl_text() {
    local text="$1" n="${2:-}"
    js "(function(t,n){var els=[].slice.call(document.querySelectorAll('button,a,[role=menuitem],[role=tab],[role=radio],[role=checkbox],[role=switch],label,summary,th,td'));var norm=function(e){return e.textContent.replace(/\\s+/g,' ').trim();};var el=els.find(function(e){return norm(e)===t;})||els.find(function(e){return norm(e).indexOf(t)===0;});if(!el){return 'NOT FOUND: '+t;}window.__docHl(el,n);return 'ok';})(\"$text\",\"$n\")"
}

# Outline the first element matching a CSS selector; optional $2 is a step number badge.
hl_css() {
    local sel="$1" n="${2:-}"
    js "(function(s,n){var el=document.querySelector(s);if(!el){return 'NOT FOUND: '+s;}window.__docHl(el,n);return 'ok';})(\"$sel\",\"$n\")"
}

# Install the highlighter once per page load (idempotent).
hl_init() {
    js "window.__docHl=window.__docHl||function(el,n){el.setAttribute('data-doc-hl','');el.style.outline='3px solid ${HL_COLOR}';el.style.outlineOffset='3px';el.style.borderRadius='8px';if(n){var r=el.getBoundingClientRect();var b=document.createElement('div');b.setAttribute('data-doc-badge','');b.textContent=n;b.style.cssText='position:absolute;z-index:99999;left:'+(r.left+window.scrollX-14)+'px;top:'+(r.top+window.scrollY-14)+'px;width:28px;height:28px;border-radius:50%;background:${HL_COLOR};color:#fff;font:700 15px/28px system-ui,sans-serif;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.35)';document.body.appendChild(b);}};'ready'" >/dev/null
}

# Remove every highlight and badge.
unhl() {
    js "document.querySelectorAll('[data-doc-hl]').forEach(function(e){e.style.outline='';e.style.outlineOffset='';e.style.borderRadius='';e.removeAttribute('data-doc-hl');});document.querySelectorAll('[data-doc-badge]').forEach(function(e){e.remove();});'cleared'" >/dev/null
}

# Scroll so the first element matching the selector sits near the top of the viewport.
scroll_to() {
    js "(function(s){var el=document.querySelector(s);if(!el){return 'NOT FOUND: '+s;}var y=el.getBoundingClientRect().top+window.scrollY-${2:-24};window.scrollTo(0,y);return 'scrolled to '+y;})(\"$1\")"
    sleep 0.3
}

# Scroll so the first element whose text starts with $1 sits near the top of the viewport.
scroll_to_text() {
    js "(function(t){var els=[].slice.call(document.querySelectorAll('h1,h2,h3,h4,button,a,label,legend,div,span'));var el=els.find(function(e){return e.children.length<4&&e.textContent.replace(/\\s+/g,' ').trim().indexOf(t)===0;});if(!el){return 'NOT FOUND: '+t;}var y=el.getBoundingClientRect().top+window.scrollY-${2:-24};window.scrollTo(0,y);return 'scrolled to '+y;})(\"$1\")"
    sleep 0.3
}

# Click the first control whose text starts with $1 (buttons, links, labels, radios).
click_text() {
    js "(function(t){var els=[].slice.call(document.querySelectorAll('button,a,[role=menuitem],[role=tab],[role=radio],[role=checkbox],[role=switch],label,summary'));var norm=function(e){return e.textContent.replace(/\\s+/g,' ').trim();};var el=els.find(function(e){return norm(e)===t;})||els.find(function(e){return norm(e).indexOf(t)===0;});if(!el){return 'NOT FOUND: '+t;}el.click();return 'clicked';})(\"$1\")"
    settle
}

# Remove toasts and other transient chrome before a shot.
tidy() {
    js "if(document.activeElement)document.activeElement.blur();document.querySelectorAll('[data-flux-toast], ui-toast, [data-flux-toast-group]').forEach(function(e){e.remove();});'tidy'" >/dev/null
}

login() {
    go /login
    "$B" fill 'input[type=email]' "$1" >/dev/null
    "$B" fill 'input[type=password]' "$2" >/dev/null
    "$B" click 'button[type=submit]' >/dev/null
    settle 30000 1
    "$B" url
}

logout() {
    go /dashboard
    js "document.querySelector('form[action\$=\"/logout\"]').submit();'bye'" >/dev/null
    settle 10000 1
}

is_dark() {
    js "document.documentElement.classList.contains('dark')"
}

# Bounding box (CSS px, page coordinates) of the closest card/section around the first
# element whose text starts with $1. Prints "x,y,w,h" for shot_clip.
rect_of_text() {
    js "(function(t,up){var els=[].slice.call(document.querySelectorAll('h1,h2,h3,h4,div,span,label,legend,p'));var el=els.find(function(e){return e.children.length<3&&e.textContent.replace(/\\s+/g,' ').trim().indexOf(t)===0;});if(!el){return 'NOT FOUND';}var box=el;for(var i=0;i<up;i++){if(box.parentElement){box=box.parentElement;}}var r=box.getBoundingClientRect();return Math.max(0,Math.floor(r.left+window.scrollX-8))+','+Math.max(0,Math.floor(r.top+window.scrollY-8))+','+Math.ceil(r.width+16)+','+Math.ceil(r.height+16);})(\"$1\",${2:-2})"
}

# Bounding box of the first element matching a CSS selector, padded by 8px.
rect_of_css() {
    js "(function(s){var el=document.querySelector(s);if(!el){return 'NOT FOUND';}var r=el.getBoundingClientRect();return Math.max(0,Math.floor(r.left+window.scrollX-8))+','+Math.max(0,Math.floor(r.top+window.scrollY-8))+','+Math.ceil(r.width+16)+','+Math.ceil(r.height+16);})(\"$1\")"
}

# shot_clip <name> <x,y,w,h>  → a cropped capture (page coordinates, CSS px).
shot_clip() {
    local out="$IMG/$1.png"
    "$B" screenshot "$out" --clip "$2" >/dev/null
    echo "shot: $out ($2)"
}

# The open Flux modal, if any.
shot_modal() {
    local out="$IMG/$1.png"
    "$B" screenshot "$out" --selector "dialog[open]" >/dev/null
    echo "shot: $out (modal)"
}

# Accept the next native confirm() dialog raised by a wire:confirm action.
confirm_then() {
    "$B" dialog-accept >/dev/null 2>&1 || true
    click_text "$1"
}

close_modal() {
    "$B" press Escape >/dev/null
    sleep 0.4
}

# JSON-encode a string for safe interpolation into page JavaScript.
jstr() {
    node -e 'process.stdout.write(JSON.stringify(process.argv[1]))' -- "$1"
}

# Click the first element matching a CSS selector via JavaScript (works for hidden inputs).
click_css() {
    js "(function(s){var el=document.querySelector(s);if(!el){return 'NOT FOUND: '+s;}el.click();return 'clicked';})($(jstr "$1"))"
    settle
}

# Bounding box of the card (nearest bordered/rounded ancestor) whose heading text is $1.
rect_of_card() {
    js "(function(t){var els=[].slice.call(document.querySelectorAll('h1,h2,h3,h4,ui-label,div,span,p,legend,label'));var m=els.filter(function(e){return e.textContent.replace(/\\s+/g,' ').trim().indexOf(t)===0;});if(!m.length){return 'NOT FOUND';}m.sort(function(a,b){return a.textContent.length-b.textContent.length;});var el=m[0];var box=el;while(box.parentElement&&!/rounded-(xl|lg|2xl)|border/.test(box.className||'')){box=box.parentElement;}var r=box.getBoundingClientRect();return Math.max(0,Math.floor(r.left+window.scrollX-8))+','+Math.max(0,Math.floor(r.top+window.scrollY-8))+','+Math.ceil(r.width+16)+','+Math.ceil(r.height+16);})($(jstr "$1"))"
}

hl_css() {
    local n="${2:-}"
    js "(function(s,n){var el=document.querySelector(s);if(!el){return 'NOT FOUND: '+s;}window.__docHl(el,n);return 'ok';})($(jstr "$1"),$(jstr "$n"))"
}

rect_of_css() {
    js "(function(s){var el=document.querySelector(s);if(!el){return 'NOT FOUND';}var r=el.getBoundingClientRect();return Math.max(0,Math.floor(r.left+window.scrollX-8))+','+Math.max(0,Math.floor(r.top+window.scrollY-8))+','+Math.ceil(r.width+16)+','+Math.ceil(r.height+16);})($(jstr "$1"))"
}

# Set the value of the FIRST element matching a selector and fire an input event (Livewire .live).
fill_first() {
    js "(function(s,v){var el=document.querySelector(s);if(!el){return 'NOT FOUND: '+s;}el.focus();el.value=v;el.dispatchEvent(new Event('input',{bubbles:true}));el.dispatchEvent(new Event('change',{bubbles:true}));return 'filled';})($(jstr "$1"),$(jstr "$2"))"
    settle
}

# Click an interactive element by its accessible name, using the refs from `browse snapshot -i`.
# Works for Flux's custom radio/checkbox elements, which ignore synthetic clicks.
click_ref() {
    local ref
    ref=$("$B" snapshot -i 2>/dev/null | grep -F "\"$1\"" | head -1 | grep -o '@e[0-9]*' | head -1 || true)
    if [ -z "$ref" ]; then
        echo "NOT FOUND ref: $1"
        return 0
    fi
    "$B" click "$ref" >/dev/null
    settle
}

# Log out from wherever we are: the sidebar form when present, else the page's own Log out button.
logout() {
    local has_form
    has_form=$(js "!!document.querySelector('form[action\$=\"/logout\"]')")
    if [ "$has_form" = "true" ]; then
        js "document.querySelector('form[action\$=\"/logout\"]').submit();'bye'" >/dev/null
    else
        click_text "Log out" >/dev/null || click_text "Log Out" >/dev/null || true
    fi
    settle 10000 1
}

# Scroll so the card whose heading text is $1 sits $2 px (default 80) below the top of the viewport.
scroll_to_card() {
    local rect y
    rect=$(rect_of_card "$1")
    if [ "$rect" = "NOT FOUND" ]; then
        echo "NOT FOUND card: $1"
        return 0
    fi
    y=${rect#*,}; y=${y%%,*}
    js "window.scrollTo(0, Math.max(0, ${y} - ${2:-80}));'scrolled'" >/dev/null
    sleep 0.3
}
