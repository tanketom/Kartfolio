/**
 * Trading-card capture helpers, shared by the profile's "Download card" and
 * /cards' PDF.
 *
 * html2canvas ignores CSS filters, so the character's drop-shadow (the dark halo
 * round the art) vanished from every download. tcAddCaptureHalos() draws that
 * shadow onto a canvas the old-fashioned way, from the art's own alpha with the
 * same colour, offset and blur the CSS filter uses, and slips it behind the art as
 * an ordinary image, which html2canvas does capture. Call it before capturing and
 * the function it returns afterwards, which takes the images away again.
 */
(function () {
    // "drop-shadow(rgba(0, 0, 0, 0.35) 0px 7.5px 18px)" → {color, x, y, blur}
    function parseDropShadow(filter) {
        const m = /drop-shadow\((rgba?\([^)]*\)|#[0-9a-f]+)\s+(-?[\d.]+)px\s+(-?[\d.]+)px\s+([\d.]+)px\)/i.exec(filter || '');
        return m ? { color: m[1], x: parseFloat(m[2]), y: parseFloat(m[3]), blur: parseFloat(m[4]) } : null;
    }

    function haloFor(art, k) {
        const s = parseDropShadow(getComputedStyle(art).filter);
        if (!s || !art.complete || !art.naturalWidth) return null;
        const w = art.offsetWidth, h = art.offsetHeight;
        if (!w || !h) return null;

        // Where object-fit: contain actually draws the picture inside the box.
        const fit = Math.min(w / art.naturalWidth, h / art.naturalHeight);
        const dw = art.naturalWidth * fit, dh = art.naturalHeight * fit;
        const dx = (w - dw) / 2, dy = (h - dh) / 2;

        const pad = Math.ceil(s.blur * 2 + Math.abs(s.x) + Math.abs(s.y));
        const canvas = document.createElement('canvas');
        canvas.width = Math.round((w + pad * 2) * k);
        canvas.height = Math.round((h + pad * 2) * k);
        const ctx = canvas.getContext('2d');
        // The art is drawn far off the canvas and only its shadow is thrown back
        // onto it, so the picture is a shadow with nothing on top.
        const away = canvas.width + 1000;
        ctx.shadowColor = s.color;
        ctx.shadowBlur = s.blur * k;   // canvas and CSS measure blur the same way
        ctx.shadowOffsetX = s.x * k + away;
        ctx.shadowOffsetY = s.y * k;
        try {
            ctx.drawImage(art, (pad + dx) * k - away, (pad + dy) * k, dw * k, dh * k);
        } catch (e) { return null; }

        const img = document.createElement('img');
        img.className = 'tc-capture-halo';
        img.alt = '';
        img.src = canvas.toDataURL('image/png');
        img.style.cssText = 'position:absolute;pointer-events:none;z-index:0;'
            + 'left:' + (art.offsetLeft - pad) + 'px;top:' + (art.offsetTop - pad) + 'px;'
            + 'width:' + (w + pad * 2) + 'px;height:' + (h + pad * 2) + 'px;';
        art.parentNode.insertBefore(img, art);
        return img;
    }

    window.tcAddCaptureHalos = function (root, scale) {
        const k = Math.max(1, scale || 3);
        const made = [];
        (root || document).querySelectorAll('.tc-art').forEach(function (art) {
            const h = haloFor(art, k);
            if (h) made.push(h);
        });
        return function () { made.forEach(function (h) { h.remove(); }); };
    };
})();
