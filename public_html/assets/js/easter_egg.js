// ============================================================
// SECRET EASTER EGG - Kart Mode
// Activated by: ↑ ↑ ↓ ↓ ← → ← → B A Enter
// Drive a SNES Mario Kart sprite around the page!
// Space to hop — hop while turning to drift!
// ============================================================

(function() {
    'use strict';

    // Classic Konami code
    var SECRET_CODE = [
        'ArrowUp', 'ArrowUp', 'ArrowDown', 'ArrowDown',
        'ArrowLeft', 'ArrowRight', 'ArrowLeft', 'ArrowRight',
        'KeyB', 'KeyA', 'Enter'
    ];

    var codePos = 0;
    var active = false;
    var canvas, ctx;
    var spriteSheet = null;
    var processedSprite = null;
    var spriteReady = false;
    var animFrameId = null;

    // Mario sprite row 0: rear view -> side -> front (12 rotation frames)
    var SPRITE_ROW_Y = 0;
    var SPRITE_ROW_H = 33;
    var SPRITE_FW = 32;
    var SPRITE_OFFSETS = [0, 32, 65, 98, 132, 165, 198, 231, 263, 296, 330, 363];
    var BG_COLORS = [[26, 132, 57], [130, 197, 237]];

    var SCALE = 2.5;
    var DRAW_W = SPRITE_FW * SCALE;
    var DRAW_H = SPRITE_ROW_H * SCALE;

    // Physics
    var kart = {
        x: 0, y: 0,
        angle: 0,
        speed: 0,
        maxSpeed: 7,
        accel: 0.22,
        brake: 0.15,
        friction: 0.965,
        turnSpeed: 0.05,
        boostTimer: 0,
        // Hop & drift
        jumpHeight: 0,
        jumpVel: 0,
        grounded: true,
        drifting: false,
        driftDir: 0,       // -1 left, 1 right
        driftCharge: 0,     // how long drift has been held
        driftAngleOffset: 0 // visual slide offset
    };

    var GRAVITY = 0.6;
    var HOP_FORCE = -7;

    var keys = {};
    var particles = [];
    var flashTimer = 0;

    // ========================================
    // SPRITE PROCESSING
    // ========================================

    function removeSpriteBG() {
        if (!spriteSheet) return;
        var c = document.createElement('canvas');
        c.width = spriteSheet.width;
        c.height = spriteSheet.height;
        var cx = c.getContext('2d');
        cx.drawImage(spriteSheet, 0, 0);
        var imgData = cx.getImageData(0, 0, c.width, c.height);
        var d = imgData.data;
        for (var i = 0; i < d.length; i += 4) {
            for (var b = 0; b < BG_COLORS.length; b++) {
                var bg = BG_COLORS[b];
                if (Math.abs(d[i] - bg[0]) < 10 &&
                    Math.abs(d[i + 1] - bg[1]) < 10 &&
                    Math.abs(d[i + 2] - bg[2]) < 10) {
                    d[i + 3] = 0;
                    break;
                }
            }
        }
        cx.putImageData(imgData, 0, 0);
        processedSprite = c;
        spriteReady = true;
    }

    function getSpriteFrame(angle) {
        var a = ((angle % (Math.PI * 2)) + Math.PI * 2) % (Math.PI * 2);
        if (a <= Math.PI) {
            return { frame: Math.min(11, Math.round((a / Math.PI) * 11)), flip: false };
        } else {
            return { frame: Math.min(11, Math.round(((Math.PI * 2 - a) / Math.PI) * 11)), flip: true };
        }
    }

    // ========================================
    // CODE DETECTION
    // ========================================

    document.addEventListener('keydown', function(e) {
        if (active) {
            keys[e.code] = true;
            if (e.code === 'ArrowUp' || e.code === 'ArrowDown' ||
                e.code === 'ArrowLeft' || e.code === 'ArrowRight' ||
                e.code === 'Space') {
                e.preventDefault();
            }
            if (e.code === 'Escape') {
                deactivate();
            }
            return;
        }

        if (e.code === SECRET_CODE[codePos]) {
            codePos++;
            if (codePos === SECRET_CODE.length) {
                codePos = 0;
                activate();
            }
        } else {
            codePos = 0;
        }
    });

    document.addEventListener('keyup', function(e) {
        if (active) keys[e.code] = false;
    });

    // ========================================
    // ACTIVATION / DEACTIVATION
    // ========================================

    function activate() {
        if (active) return;
        active = true;

        spriteSheet = new Image();
        spriteSheet.onload = removeSpriteBG;
        spriteSheet.src = '/assets/img/snes/mario_kart.png';

        canvas = document.createElement('canvas');
        canvas.id = 'kart-easter-egg';
        canvas.style.cssText = [
            'position:fixed', 'top:0', 'left:0',
            'width:100vw', 'height:100vh',
            'pointer-events:none', 'z-index:99998'
        ].join(';');
        canvas.width = window.innerWidth;
        canvas.height = window.innerHeight;
        document.body.appendChild(canvas);
        ctx = canvas.getContext('2d');
        ctx.imageSmoothingEnabled = false;

        kart.x = canvas.width / 2;
        kart.y = canvas.height / 2;
        kart.speed = 0;
        kart.angle = 0;
        kart.boostTimer = 0;
        kart.jumpHeight = 0;
        kart.jumpVel = 0;
        kart.grounded = true;
        kart.drifting = false;
        kart.driftDir = 0;
        kart.driftCharge = 0;
        kart.driftAngleOffset = 0;
        particles = [];
        keys = {};

        flashTimer = 140;

        window.addEventListener('resize', onResize);
        gameLoop();
    }

    function deactivate() {
        active = false;
        if (animFrameId) cancelAnimationFrame(animFrameId);
        if (canvas && canvas.parentNode) canvas.parentNode.removeChild(canvas);
        window.removeEventListener('resize', onResize);
        keys = {};
        particles = [];
    }

    function onResize() {
        if (!canvas) return;
        canvas.width = window.innerWidth;
        canvas.height = window.innerHeight;
        if (ctx) ctx.imageSmoothingEnabled = false;
    }

    // ========================================
    // GAME LOOP
    // ========================================

    function gameLoop() {
        if (!active) return;
        update();
        render();
        animFrameId = requestAnimationFrame(gameLoop);
    }

    function update() {
        var turning = false;
        var turnDir = 0;

        // --- Hop ---
        if (keys['Space'] && kart.grounded && !kart.drifting) {
            kart.jumpVel = HOP_FORCE;
            kart.grounded = false;
            keys['Space'] = false; // single press
        }

        if (!kart.grounded) {
            kart.jumpVel += GRAVITY;
            kart.jumpHeight += kart.jumpVel;
            if (kart.jumpHeight >= 0) {
                kart.jumpHeight = 0;
                kart.jumpVel = 0;
                kart.grounded = true;

                // Landing while turning? Start drift!
                if ((keys['ArrowLeft'] || keys['ArrowRight']) && Math.abs(kart.speed) > 2) {
                    kart.drifting = true;
                    kart.driftDir = keys['ArrowLeft'] ? -1 : 1;
                    kart.driftCharge = 0;
                    kart.driftAngleOffset = 0;
                }
            }
        }

        // --- Drift logic ---
        if (kart.drifting) {
            // Drift continues while holding the drift direction
            var holdingDrift = (kart.driftDir === -1 && keys['ArrowLeft']) ||
                               (kart.driftDir === 1 && keys['ArrowRight']);

            if (holdingDrift && Math.abs(kart.speed) > 1) {
                kart.driftCharge++;

                // Drift turn: faster inner turn + slight outward slide
                kart.angle += kart.driftDir * kart.turnSpeed * 1.4;
                kart.driftAngleOffset = kart.driftDir * 0.25; // visual slide

                // Countering is allowed at reduced rate
                if (kart.driftDir === -1 && keys['ArrowRight']) {
                    kart.angle += kart.turnSpeed * 0.5;
                } else if (kart.driftDir === 1 && keys['ArrowLeft']) {
                    kart.angle -= kart.turnSpeed * 0.5;
                }

                turning = true;

                // Drift sparks — escalate with charge
                var sparkRate = kart.driftCharge < 30 ? 0.4 : kart.driftCharge < 60 ? 0.7 : 1;
                if (Math.random() < sparkRate) {
                    var dsx = kart.x + Math.cos(kart.angle) * kart.driftDir * DRAW_W * 0.3
                        - Math.sin(kart.angle) * DRAW_H * 0.15;
                    var dsy = kart.y + Math.sin(kart.angle) * kart.driftDir * DRAW_W * 0.3
                        + Math.cos(kart.angle) * DRAW_H * 0.15;

                    var sparkColor = kart.driftCharge < 30 ? 'white' :
                                     kart.driftCharge < 60 ? 'orange' : 'red';
                    particles.push({
                        x: dsx, y: dsy,
                        vx: (Math.random() - 0.5) * 4,
                        vy: (Math.random() - 0.5) * 4,
                        life: 6 + Math.random() * 6,
                        maxLife: 12,
                        size: 2 + Math.random() * 3,
                        type: 'drift',
                        color: sparkColor
                    });
                }
            } else {
                // Released drift — boost based on charge!
                if (kart.driftCharge > 15) {
                    var boostPower = kart.driftCharge < 30 ? 20 :
                                     kart.driftCharge < 60 ? 40 : 65;
                    kart.boostTimer = boostPower;
                }
                kart.drifting = false;
                kart.driftDir = 0;
                kart.driftCharge = 0;
                kart.driftAngleOffset = 0;
            }
        }

        // --- Normal turning (only when not drifting) ---
        if (!kart.drifting) {
            var turnMult = 0.4 + Math.min(Math.abs(kart.speed) / kart.maxSpeed, 1) * 0.6;
            if (keys['ArrowLeft']) {
                kart.angle -= kart.turnSpeed * turnMult;
                turning = true;
                turnDir = -1;
            }
            if (keys['ArrowRight']) {
                kart.angle += kart.turnSpeed * turnMult;
                turning = true;
                turnDir = 1;
            }
        }

        // --- Acceleration & braking ---
        var boosted = kart.boostTimer > 0;
        var topSpeed = boosted ? kart.maxSpeed * 1.5 : kart.maxSpeed;
        var accelRate = boosted ? kart.accel * 1.4 : kart.accel;

        if (keys['ArrowUp']) {
            kart.speed = Math.min(kart.speed + accelRate, topSpeed);
        } else if (keys['ArrowDown']) {
            kart.speed = Math.max(kart.speed - kart.brake, -kart.maxSpeed * 0.35);
        }

        // Friction (slightly more during drift for slide feel)
        kart.speed *= kart.drifting ? 0.975 : kart.friction;
        if (Math.abs(kart.speed) < 0.02) kart.speed = 0;

        if (kart.boostTimer > 0) kart.boostTimer--;

        // --- Movement ---
        kart.x += Math.sin(kart.angle) * kart.speed;
        kart.y -= Math.cos(kart.angle) * kart.speed;

        // Wrap around edges
        var m = DRAW_W;
        if (kart.x < -m) kart.x = canvas.width + m;
        if (kart.x > canvas.width + m) kart.x = -m;
        if (kart.y < -m) kart.y = canvas.height + m;
        if (kart.y > canvas.height + m) kart.y = -m;

        // --- Exhaust particles ---
        if (keys['ArrowUp'] && Math.abs(kart.speed) > 0.5) {
            var ex = kart.x - Math.sin(kart.angle) * DRAW_W * 0.35;
            var ey = kart.y + Math.cos(kart.angle) * DRAW_H * 0.35;
            particles.push({
                x: ex + (Math.random() - 0.5) * 6,
                y: ey + (Math.random() - 0.5) * 6,
                vx: -Math.sin(kart.angle) * (0.5 + Math.random()) + (Math.random() - 0.5) * 0.8,
                vy: Math.cos(kart.angle) * (0.5 + Math.random()) + (Math.random() - 0.5) * 0.8,
                life: 22 + Math.random() * 12,
                maxLife: 34,
                size: 3 + Math.random() * 4,
                type: 'exhaust'
            });
        }

        // Non-drift turning sparks
        if (turning && !kart.drifting && Math.abs(kart.speed) > 3.5) {
            var side2 = turnDir || (keys['ArrowLeft'] ? -1 : 1);
            var sx2 = kart.x + Math.cos(kart.angle) * side2 * DRAW_W * 0.25
                - Math.sin(kart.angle) * DRAW_H * 0.2;
            var sy2 = kart.y + Math.sin(kart.angle) * side2 * DRAW_W * 0.25
                + Math.cos(kart.angle) * DRAW_H * 0.2;
            particles.push({
                x: sx2, y: sy2,
                vx: (Math.random() - 0.5) * 3,
                vy: (Math.random() - 0.5) * 3,
                life: 6 + Math.random() * 8,
                maxLife: 14,
                size: 2 + Math.random() * 2.5,
                type: 'spark'
            });
        }

        // Boost trail
        if (boosted) {
            for (var bt = 0; bt < 3; bt++) {
                var bx = kart.x - Math.sin(kart.angle) * DRAW_W * 0.3;
                var by = kart.y + Math.cos(kart.angle) * DRAW_H * 0.3;
                particles.push({
                    x: bx + (Math.random() - 0.5) * 10,
                    y: by + (Math.random() - 0.5) * 10,
                    vx: -Math.sin(kart.angle) * (2 + Math.random() * 2),
                    vy: Math.cos(kart.angle) * (2 + Math.random() * 2),
                    life: 10 + Math.random() * 8,
                    maxLife: 18,
                    size: 4 + Math.random() * 4,
                    type: 'boost'
                });
            }
        }

        // Update particles
        for (var i = particles.length - 1; i >= 0; i--) {
            var p = particles[i];
            p.x += p.vx;
            p.y += p.vy;
            p.life--;
            if (p.type === 'exhaust') {
                p.size *= 1.02;
                p.vx *= 0.95;
                p.vy *= 0.95;
            }
            if (p.life <= 0) particles.splice(i, 1);
        }

        if (flashTimer > 0) flashTimer--;
    }

    function render() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);

        // --- Particles (behind kart) ---
        for (var i = 0; i < particles.length; i++) {
            var p = particles[i];
            var alpha = p.life / p.maxLife;

            if (p.type === 'exhaust') {
                ctx.globalAlpha = alpha * 0.45;
                ctx.fillStyle = '#b8b8b8';
                ctx.beginPath();
                ctx.arc(p.x, p.y, p.size, 0, Math.PI * 2);
                ctx.fill();
            } else if (p.type === 'spark') {
                var sparkColors = ['#FFD700', '#FF8C00', '#FFFFFF', '#FF4500'];
                ctx.globalAlpha = alpha;
                ctx.fillStyle = sparkColors[Math.floor(Math.random() * sparkColors.length)];
                ctx.fillRect(p.x, p.y, p.size, p.size);
            } else if (p.type === 'drift') {
                ctx.globalAlpha = alpha;
                if (p.color === 'white') ctx.fillStyle = '#FFFFFF';
                else if (p.color === 'orange') ctx.fillStyle = '#FF8C00';
                else ctx.fillStyle = '#FF2200';
                ctx.fillRect(p.x, p.y, p.size, p.size);
            } else if (p.type === 'boost') {
                ctx.globalAlpha = alpha * 0.7;
                ctx.fillStyle = alpha > 0.5 ? '#FF4400' : '#FFaa00';
                ctx.beginPath();
                ctx.arc(p.x, p.y, p.size * alpha, 0, Math.PI * 2);
                ctx.fill();
            }
        }
        ctx.globalAlpha = 1;

        // Visual Y offset for hop and drift slide
        var drawY = kart.y + kart.jumpHeight;
        var drawX = kart.x;

        // During drift, slide the visual position slightly outward
        if (kart.drifting) {
            drawX += Math.cos(kart.angle) * kart.driftDir * 4;
            drawY += Math.sin(kart.angle) * kart.driftDir * 4;
        }

        // --- Shadow (stays on ground, scales with height) ---
        var shadowScale = 1 + kart.jumpHeight * 0.008; // shrinks shadow as kart rises
        ctx.save();
        ctx.globalAlpha = Math.max(0.08, 0.2 * shadowScale);
        ctx.fillStyle = '#000';
        ctx.beginPath();
        ctx.ellipse(kart.x, kart.y + DRAW_H * 0.35,
            DRAW_W * 0.35 * shadowScale, DRAW_H * 0.12 * shadowScale,
            0, 0, Math.PI * 2);
        ctx.fill();
        ctx.restore();

        // --- Speed bar ABOVE kart ---
        if (Math.abs(kart.speed) > 0.5) {
            var speedPct = Math.abs(kart.speed) / kart.maxSpeed;
            if (kart.boostTimer > 0) speedPct = Math.min(speedPct, 1.5) / 1.5; // normalize during boost
            var barW = 40;
            var barH = 3;
            var barX = drawX - barW / 2;
            var barY = drawY - DRAW_H / 2 - 10;

            ctx.save();
            ctx.globalAlpha = 0.5;
            ctx.fillStyle = '#222';
            ctx.fillRect(barX, barY, barW, barH);

            var color = kart.boostTimer > 0 ? '#FF4400' :
                        speedPct < 0.5 ? '#4CAF50' : speedPct < 0.8 ? '#FF9800' : '#F44336';
            ctx.fillStyle = color;
            ctx.fillRect(barX, barY, barW * Math.min(speedPct, 1), barH);
            ctx.restore();
        }

        // --- Drift charge indicator ---
        if (kart.drifting && kart.driftCharge > 10) {
            var chargeLevel = kart.driftCharge < 30 ? 1 : kart.driftCharge < 60 ? 2 : 3;
            var chargeColor = chargeLevel === 1 ? '#FFFFFF' : chargeLevel === 2 ? '#FF8C00' : '#FF2200';
            ctx.save();
            ctx.globalAlpha = 0.6 + Math.sin(Date.now() * 0.02) * 0.3;
            ctx.fillStyle = chargeColor;
            // Small dots flanking the kart
            ctx.beginPath();
            ctx.arc(drawX - DRAW_W * 0.5 - 4, drawY, 3, 0, Math.PI * 2);
            ctx.arc(drawX + DRAW_W * 0.5 + 4, drawY, 3, 0, Math.PI * 2);
            ctx.fill();
            ctx.restore();
        }

        // --- Kart sprite ---
        if (spriteReady && processedSprite) {
            var displayAngle = kart.angle + kart.driftAngleOffset;
            var sf = getSpriteFrame(displayAngle);
            var srcX = SPRITE_OFFSETS[sf.frame];
            var srcY = SPRITE_ROW_Y;
            var srcW = SPRITE_FW;
            var srcH = SPRITE_ROW_H;

            ctx.save();
            ctx.translate(drawX, drawY);

            // Tilt when turning at speed (not during drift — drift has its own visual)
            if (!kart.drifting) {
                var tilt = 0;
                if (keys['ArrowLeft'] && Math.abs(kart.speed) > 2) tilt = -0.08;
                if (keys['ArrowRight'] && Math.abs(kart.speed) > 2) tilt = 0.08;
                if (tilt) ctx.rotate(tilt);
            } else {
                // Drift visual: slight body rotation into the drift
                ctx.rotate(kart.driftDir * 0.12);
            }

            // Boost glow
            if (kart.boostTimer > 0) {
                ctx.shadowColor = '#FF4400';
                ctx.shadowBlur = 20 + Math.random() * 10;
            }

            if (sf.flip) ctx.scale(-1, 1);
            ctx.drawImage(processedSprite,
                srcX, srcY, srcW, srcH,
                -DRAW_W / 2, -DRAW_H / 2, DRAW_W, DRAW_H
            );
            ctx.restore();
        } else {
            // Fallback
            ctx.save();
            ctx.translate(drawX, drawY);
            ctx.rotate(kart.angle);
            ctx.fillStyle = '#E60012';
            ctx.fillRect(-10, -14, 20, 28);
            ctx.fillStyle = '#FFD700';
            ctx.fillRect(-6, -16, 12, 5);
            ctx.restore();
        }

        // --- Activation flash ---
        if (flashTimer > 0) {
            var fa = flashTimer > 100 ? 1 : flashTimer / 100;
            var fs = flashTimer > 110 ? 1 + (140 - flashTimer) * 0.015 : 1;

            ctx.save();
            ctx.globalAlpha = fa;

            ctx.fillStyle = 'rgba(0, 0, 0, ' + (fa * 0.6) + ')';
            ctx.fillRect(0, canvas.height / 2 - 80, canvas.width, 120);

            // Checkered borders
            var bw = 12;
            for (var cx2 = 0; cx2 < canvas.width; cx2 += bw) {
                var topRow = (Math.floor(cx2 / bw) % 2 === 0);
                ctx.fillStyle = topRow ? '#fff' : '#000';
                ctx.fillRect(cx2, canvas.height / 2 - 82, bw, bw);
                ctx.fillStyle = topRow ? '#000' : '#fff';
                ctx.fillRect(cx2, canvas.height / 2 - 82 + bw, bw, bw);
                ctx.fillStyle = topRow ? '#000' : '#fff';
                ctx.fillRect(cx2, canvas.height / 2 + 28, bw, bw);
                ctx.fillStyle = topRow ? '#fff' : '#000';
                ctx.fillRect(cx2, canvas.height / 2 + 28 + bw, bw, bw);
            }

            ctx.font = 'bold ' + Math.floor(42 * fs) + 'px -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillStyle = 'rgba(0,0,0,0.6)';
            ctx.fillText('\uD83C\uDFC1 KART MODE \uD83C\uDFC1', canvas.width / 2 + 2, canvas.height / 2 - 22 + 2);
            ctx.fillStyle = '#FFFFFF';
            ctx.fillText('\uD83C\uDFC1 KART MODE \uD83C\uDFC1', canvas.width / 2, canvas.height / 2 - 22);

            if (flashTimer > 40) {
                ctx.font = 'bold 15px -apple-system, BlinkMacSystemFont, sans-serif';
                ctx.fillStyle = 'rgba(255,255,255,0.8)';
                ctx.fillText('Arrow keys to drive \u2022 Space to hop \u2022 ESC to exit', canvas.width / 2, canvas.height / 2 + 12);
            }

            ctx.restore();
        }

        // --- ESC hint ---
        if (flashTimer <= 0) {
            ctx.save();
            ctx.font = '11px -apple-system, sans-serif';
            ctx.fillStyle = 'rgba(150, 150, 150, 0.35)';
            ctx.textAlign = 'right';
            ctx.fillText('ESC to exit kart mode', canvas.width - 14, canvas.height - 14);
            ctx.restore();
        }
    }

})();
