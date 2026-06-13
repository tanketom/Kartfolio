-- ============================================================================
-- Mac's Mushroom Musings — handwritten seed for all 96 MK8 Deluxe tracks.
--
-- Use this when the Gemini quota is exhausted (or just to skip the per-track
-- API calls entirely). Each musing is in Mac's voice: ~80–110 words, one
-- hazard, one line/tip, one character archetype, the track named once,
-- friendly close. Pippin (Mac's young Toad nephew) appears in ~12 of the 96.
--
-- INSERT OR IGNORE — existing rows are preserved, so loading this is safe
-- even if you've already generated some musings via /admin. If you want to
-- OVERWRITE everything with this seed, find/replace the next 96 statements
-- to use `INSERT OR REPLACE`.
--
-- Load:   sqlite3 /path/to/league.sqlite < private/data/track_musings_seed.sql
-- Verify: sqlite3 /path/to/league.sqlite "SELECT COUNT(*) FROM track_musings;"
--         (should be 96, or 96 + however many you'd already kept)
-- ============================================================================

BEGIN TRANSACTION;

-- ── Mushroom Cup ───────────────────────────────────────────────────────────
INSERT OR IGNORE INTO track_musings (track_name, body, model_used) VALUES
('Mario Kart Stadium',
 'Mario Kart Stadium is a polite handshake — the kind of place every season has to open. Don''t let the fireworks distract you from the early chicane; that''s where positions get sorted long before lap three. Keep your boost charges low and steady through the first two corners, then unload them on the back straight. Middleweights like Yoshi or Daisy do well here — enough grip to hold the line, enough kick to punish a slipstream. Easy money if you start clean. Off you go, friend.',
 'claude-handwritten'),

('Water Park',
 'Water Park''s prettier than it is dangerous, but it''ll catch you if you treat it as a parade. The big aquarium drop in the middle is where most racers over-commit and clip the wall — ease off a touch before the dolphins, then drift wide. Anti-grav lets you bump speed off the support beams, so look for those instead of fighting for the inside line. A cruiser like Peach or a heavy like Bowser eats this place up. Lakitu always sounds cheerful from the start gate here. Have fun out there.',
 'claude-handwritten'),

('Sweet Sweet Canyon',
 'Sweet Sweet Canyon is loud in the best way — donut hoops, gummy walls, item boxes scattered like they were stocked by a confectioner with poor budgeting. Take the lower fork through the cake section; it''s a touch shorter and lighter on traffic. Watch the candy-cane pillars near the finish; people forget they''re solid. Lights like Toad or Toadette can thread the gaps the heavies can''t. Pippin tried to sell cotton candy at the finish line last week and lost half of it to the wind. Race smart, friend.',
 'claude-handwritten'),

('Thwomp Ruins',
 'Thwomp Ruins is the kind of track Mario circuits would call "characterful." The Thwomps themselves aren''t random — they fall on a beat, and once you''ve taken a lap, you know which ones to thread. The narrow stone bridge late in the lap is the bigger trap: greedy lines get bumped into the gap. Stay center, stay patient, and let a lightweight like Lakitu or Koopa Troopa carve through. The dust gets in everywhere by lap three, but that''s part of the charm. Run your race, and you''ll be alright.',
 'claude-handwritten');

-- ── Flower Cup ─────────────────────────────────────────────────────────────
INSERT OR IGNORE INTO track_musings (track_name, body, model_used) VALUES
('Mario Circuit',
 'Mario Circuit looks tame at a glance, but that anti-grav loop in the middle changes the math more than people credit. Take the inside on the climb — gravity stops being your enemy — and unload your mushroom on the back stretch where the road flattens. The trickiest bit is the final S-curve; ride the kerb but don''t kiss the wall, you''ll lose half a second. Middleweights handle the loop best. Keep one shell in your pocket for the last 200 meters; someone always tries the shortcut and pays for it. Go get ''em.',
 'claude-handwritten'),

('Toad Harbor',
 'Toad Harbor''s got three routes through the main square, and most racers pick the same one. Take the alley to the right of the trolley — it''s narrower, but you''ll come out on the boost strip ahead of half the field. Mind the trolley itself; it doesn''t change its tune for anyone. Lights and cruisers both do well here; the heavies struggle with the cobblestones. Keep your eyes up and don''t stare at the minimap. A clean run through the harbor pays better than a brave one. Enjoy yourself.',
 'claude-handwritten'),

('Twisted Mansion',
 'Twisted Mansion has fewer real corners than it has ways to disorient you. The flooded ballroom mid-lap looks like a straight, but it slows your kart noticeably — ride the edge along the chandeliers instead. Watch the Boos; they''re cosmetic but the lighting trick they pull breaks your depth perception when you''re tired. Cruisers with good handling — Daisy, Rosalina — do well in the tight halls. Pop your boosts coming out of the side staircase, not into it. Don''t take it too seriously; it''s just an old house. Off you go, friend.',
 'claude-handwritten'),

('Shy Guy Falls',
 'Shy Guy Falls is one of the prettier climbs in the rotation, but the anti-grav waterfall section eats first-timers. Stay tight to the right wall going up — the water spray pushes you left whether you feel it or not. Up top, the snowy descent is where the real time is made; carry your speed through the first long curve and don''t tap brake. Heavies like Wario actually thrive on the descent because the kerbs forgive them. Watch Lakitu at the top; he gives you a half-second pause if you take the corner clean. Good luck.',
 'claude-handwritten');

-- ── Star Cup ───────────────────────────────────────────────────────────────
INSERT OR IGNORE INTO track_musings (track_name, body, model_used) VALUES
('Sunshine Airport',
 'Sunshine Airport''s a forgiving start — runway-wide and smooth — until you hit the anti-grav loop over the terminal. That''s where positions actually shake out. Take the inside on the climb; the outside line looks faster but the gravity flip costs you a beat. Watch the jet engines on the apron — they push you off-line if you cut too close. Cruisers like Daisy do well here, with enough handling for the loop and enough mass to hold a slipstream. End with a clean apex into the final tunnel and you''ll be on the podium. Have fun out there, friend.',
 'claude-handwritten'),

('Dolphin Shoals',
 'Dolphin Shoals looks like a holiday, then the eel section reminds you it''s a Mario Kart track. The big sea creature blocks lines if you''re greedy — hold center until you see its tail flick clear. The anti-grav stretch through the coral is where bold drivers gain a full second, but the kerbs are unforgiving. Mid-weights with strong handling — Yoshi, Peach — slot in nicely. Pop your shroom in the underwater tunnel, not before. Don''t waste a glance on the dolphins; they''re prettier than they are helpful. Good racing.',
 'claude-handwritten'),

('Electrodrome',
 'Electrodrome is a track that rewards patience more than aggression. The big disco section in the middle has wide curves that tempt heavies into overshooting; let off the throttle a hair before the apex and you''ll exit faster than the racer who held it down. The pink-blue boost ribbon is real time savings if you can chain three of them. Lights like Lakitu and the Koopalings glide through. Don''t bother trying the rumored backstage shortcut — Lakitu pulls you out every time. Keep your rhythm and enjoy the music. Off you go.',
 'claude-handwritten'),

('Mount Wario',
 'Mount Wario''s the unusual one — start to finish, no full laps, so every section counts. The opening cargo plane drop is forgiving, but the snow section after has hidden ice patches that yank you sideways. Take the icy chicane mid-mountain wide; cutting it costs more than you save. The final descent into the goal is where heavies like Wario or Donkey Kong actually earn their weight class. Pippin set up a hot cocoa stand at the gondola last winter; sold three cups before he tipped the whole tray. Don''t be Pippin. Race clean.',
 'claude-handwritten');

-- ── Special Cup ────────────────────────────────────────────────────────────
INSERT OR IGNORE INTO track_musings (track_name, body, model_used) VALUES
('Cloudtop Cruise',
 'Cloudtop Cruise tests your nerves before your hands. The thunder section in the middle isn''t random — you can see the bolts charge before they hit, so watch the cloud color. The anti-grav clouds are where mid-weights pull ahead; they hold the line where heavies skip. The big jet shortcut on lap two is real but tight — only take it if you''re already comfortable here. Don''t stare at the storm; stare at the track. Lakitu sounds nervous over this one, which is unusual. Trust your kart and you''ll be fine, friend.',
 'claude-handwritten'),

('Bone-Dry Dunes',
 'Bone-Dry Dunes is loud, dusty, and trickier than it looks on the minimap. The sand pits aren''t decorative — they grab any kart that strays a meter wide. Stay center through the bone arch section; the inside line is sand. Heavies like Bowser and Wario actually love this place because the bumps don''t move them. The final twisty descent rewards a held boost, not a drifted one. The wind smells like copper out here once the sun''s high. Keep your eyes forward. You''ve got this.',
 'claude-handwritten'),

('Bowser''s Castle',
 'Bowser''s Castle is the kind of track that punishes show-offs. The lava jets aren''t on a timer you can game — they''re synced to lap, so the first lap is your reconnaissance. The big Bowser statue mid-track will swipe you off the road if you take the inside; stay middle through that whole section. Anti-grav up the spiral tower is where cruisers like Rosalina shine. Save one item for the final corner — the inside cut is a trap if you don''t have a defensive shell ready. Be brave but be smart.',
 'claude-handwritten'),

('Rainbow Road',
 'Rainbow Road is the league exam. The space station section in the middle has gravity that flips three times — most racers panic and brake; you should hold steady. The narrow ribbon descents have no walls, so a single bump from a careless heavy ends your race. Lights and middleweights only on this one; heavies pay tax. The astronaut section near the end has a forgiving racing line if you take it wide. Lakitu cheers extra loud at the finish here. Whatever happens, it''s worth running. Off you go.',
 'claude-handwritten');

-- ── Shell Cup ──────────────────────────────────────────────────────────────
INSERT OR IGNORE INTO track_musings (track_name, body, model_used) VALUES
('Wii Moo Moo Meadows',
 'Wii Moo Moo Meadows looks like a postcard and races like one too — gentle until the cow herd in the back field decides to wander. The animals are on a pattern; if you take a fresh lap with eyes up, you''ll see it. Stay inside on the windmill chicane; the outside loses you a half-second. Cruisers and lights both do well here; heavies oversteer the wood-fence corners. Pippin tried to milk a cow during practice once and got chased the length of the back straight. Have a kind race, friend — this place doesn''t deserve aggression.',
 'claude-handwritten'),

('GBA Mario Circuit',
 'GBA Mario Circuit is the old guard''s track — flat, fast, and full of oil slicks that haven''t moved in twenty years. Memorize the slick positions on lap one and you''ll glide through the rest. The hairpin after the start-finish is where almost everyone overshoots; lift early. Middleweights with strong drift, like Yoshi, are the safe pick. The shortcut through the dirt cut isn''t worth the time loss anymore. Hear Lakitu doing the old victory chime when you cross? That alone makes the trip worth it. Easy money if you''re patient.',
 'claude-handwritten'),

('DS Cheep Cheep Beach',
 'DS Cheep Cheep Beach moves with the tide more than people realize — the water section runs faster at low water than at high, so glance at it on lap one. The wood jump halfway through is a clean speed boost if you''re centered; clip the edge and you slow to a crawl. Lightweights like Toad and Koopa get an honest advantage on the sand. The cheep cheeps themselves are decoration — don''t waste an item on them. Keep one mushroom for the last beach straight. Trust the line. You''ll do well.',
 'claude-handwritten'),

('N64 Toad''s Turnpike',
 'N64 Toad''s Turnpike hasn''t gotten any kinder in twenty-some years. The cars on the highway are the entire track — they''re not obstacles, they''re the puzzle. Tuck behind a truck for the slipstream, then break out on the inside of the bend. Avoid the buses; they take the racing line by accident and don''t yield. Cruisers and heavies do better here because they don''t get pushed around by drafts. The night rendition with the headlights is gorgeous if you can spare a glance, which you can''t. Stay sharp.',
 'claude-handwritten');

-- ── Banana Cup ─────────────────────────────────────────────────────────────
INSERT OR IGNORE INTO track_musings (track_name, body, model_used) VALUES
('GCN Dry Dry Desert',
 'GCN Dry Dry Desert is a long course that rewards stamina over flash. The sand whirlpool in the middle is a guaranteed kart trap if you flirt with it — give it three kart-lengths of clearance. The pyramid section has a sneaky inside line that saves a beat, but only if you''ve boosted out of the previous turn cleanly. Mid-weights with strong off-road traction are the call. Watch the Pokeys — they''re slow but they don''t dodge. The wind picks up around lap two and smells like sun-baked clay. Pace yourself, friend.',
 'claude-handwritten'),

('SNES Donut Plains 3',
 'SNES Donut Plains 3 is short, simple, and easy to underestimate. The double-figure-eight layout means you''ll meet other racers head-on if you take a shortcut wrong, so know your route. The water section is shallow but slow — stay on the rope bridge. Lights are best here; the track''s narrow and rewards quick lines over brute force. The old donut-shaped patches are still recognizable from the SNES original. Save a triple-banana for the back loop; it''s where overtakers love to commit. Quick race, quick result. Have at it.',
 'claude-handwritten'),

('N64 Royal Raceway',
 'N64 Royal Raceway is wide, regal, and tempts racers into laziness. The big sweeping curves around the palace look easy and they are — but the climb to the castle gates eats your boost if you don''t time it right. Hit the ramp at full throttle, not after braking. Cruisers like Peach (fitting, this being her place) handle the long curves best. The optional detour around the castle is for tourists. Stay on the racing line. Lakitu sounds especially proud at the finish here for some reason. Go race well.',
 'claude-handwritten'),

('3DS DK Jungle',
 '3DS DK Jungle is loud, busy, and full of small obstacles that add up. The vine-swing sections aren''t decorative — they boost you if you ride the underside instead of jumping. The river crossing on lap two slows mid-weights and stops heavies; lights with floaty handling do best. Watch the Tikis — they''re aggressive and they target the leader, so first place isn''t always the best place mid-lap. Don''t bother with the gorilla side-route; loses time. Hear the drums? They speed up on the final lap. Run it brave.',
 'claude-handwritten');

-- ── Leaf Cup ───────────────────────────────────────────────────────────────
INSERT OR IGNORE INTO track_musings (track_name, body, model_used) VALUES
('DS Wario Stadium',
 'DS Wario Stadium is one of Wario''s vanity projects — flashy, loud, and trickier than the layout suggests. The big jump halfway through wants you to trick off it, but the landing zone is narrow; only commit if you''re centered. The mud section near the end slows heavies more than anyone, ironically. Mid-weights with strong drift have the easiest day. The cheering crowd masks the audio cues for items being thrown your way; trust the minimap, not your ears. Save your final mushroom for the last straight. Enjoy yourself.',
 'claude-handwritten'),

('GCN Sherbet Land',
 'GCN Sherbet Land is colder than it looks and meaner than it sounds. The freezies — those little ice spirits in the middle — will lock your kart for two seconds if you graze them, which costs more than any item shot. Stay tight to the rink wall through the ice section. Heavies do surprisingly well here because the bumps don''t move them; lights skid. The penguin cluster is purely cosmetic. Don''t drift on the polished ice; ride straight and let physics carry you. Bundle up and have fun, friend.',
 'claude-handwritten'),

('3DS Music Park',
 '3DS Music Park times its hazards to the soundtrack — listen, don''t watch. The piano-key section drops or rises with the beat; the rhythm tells you when to commit. The big xylophone in the middle has a forgiving racing line down the center notes. Lights and cruisers thread the music best; heavies miss the beat. The drum jump at the start of the final lap costs you a beat if you brake; hold throttle. The whole track''s a song, really. Stay in tempo. Race well.',
 'claude-handwritten'),

('N64 Yoshi Valley',
 'N64 Yoshi Valley is famous for hiding ranks until the final straight, and the branching paths are why — pick the leftmost on lap one, the middle on lap two, the right on lap three. The egg in the center actually rolls now and again; stay clear. The narrow ridges have no margin, so middleweights with tight handling rule here. Don''t trust the minimap until the last hundred meters. Lakitu sounds genuinely surprised at this track''s finishing order every time. Just race, and see what happens. Off you go.',
 'claude-handwritten');

-- ── Lightning Cup ──────────────────────────────────────────────────────────
INSERT OR IGNORE INTO track_musings (track_name, body, model_used) VALUES
('DS Tick-Tock Clock',
 'DS Tick-Tock Clock is the rare track that''s actively trying to throw you off. The big pendulums swing on a beat; learn the beat in practice and you''ll never touch one. The gear section in the middle moves the road under you — don''t fight it, ride with it. Lights and cruisers thrive here; heavies struggle with the timing. The minute hand near the finish is a hazard you can''t see coming on lap one. Pippin claims he can hear what time it is on this track; he''s wrong but he loves saying it. Tick along, friend.',
 'claude-handwritten'),

('3DS Piranha Plant Slide',
 '3DS Piranha Plant Slide is an underground romp through a Roman aqueduct full of unhappy plants. The water current in the slide section pushes you outward; lean in to compensate. The piranha plants snap on a pattern — count three, then move. Lightweights have the edge here because the corkscrew sections reward quick handling. The shortcut through the side pipe is real, but only saves time if your boost is fresh. Don''t try to outrun the current; work with it. Surface clean and proud. Off you go.',
 'claude-handwritten'),

('Wii Grumble Volcano',
 'Wii Grumble Volcano changes between laps — chunks of road fall away by lap three, so memorize the new lines fast. The lava geysers fire on a count; if you saw one go on lap one, it''ll go again on lap two at the same beat. Heavies eat this place; the rough terrain doesn''t bother them and the lava doesn''t either. The ramp jump near the finish is for show — don''t slow for it. Lakitu won''t pull you out fast here, so be cautious. Stay hot but stay sharp.',
 'claude-handwritten'),

('N64 Rainbow Road',
 'N64 Rainbow Road is the long one — single-lap-only — and the track that rewards memorization more than skill. The chain chomps roll in their own pattern; learn it and you''ll never get clipped. The narrow ribbons have small walls now, but a hard bump still kills you. Mid-weights are the sweet spot; lights fly off easy, heavies plow through the rails. The shortcut over the corner about a third of the way through saves nearly a full second if you''ve got a mushroom. Take your time and enjoy the view, friend.',
 'claude-handwritten');

-- ── Egg Cup ────────────────────────────────────────────────────────────────
INSERT OR IGNORE INTO track_musings (track_name, body, model_used) VALUES
('GCN Yoshi Circuit',
 'GCN Yoshi Circuit is shaped like a dinosaur and races like a long curve marathon. The tail section at the start is deceptively narrow — heavies clip it every time. Stay inside on the head turn; outside loses two beats. Middleweights and lights with strong drift do best here; cornering matters more than top speed. The river jump on the back is a forgiving boost zone if you hit it centered. Watch for surprise items in the central spine — it''s where greedy racers throw shells. Run a clean lap and you''ll feel it. Off you go.',
 'claude-handwritten'),

('Excitebike Arena',
 'Excitebike Arena has a different track layout every race — the ramp positions actually move. Lap one is your scouting lap; don''t expect to win it. The dirt slows heavies more than anyone, so cruisers and lights are the safer call. Ride the ramps centered for the boost, not the trick — the trick costs you grip on landing. The crowd''s loud here, and the audio cues from items are easy to miss. Trust the minimap. There''s no shortcut, just clean lines. Have fun out there.',
 'claude-handwritten'),

('Dragon Driftway',
 'Dragon Driftway is a track that earns its name — the anti-grav along the dragon''s back is one long, beautiful drift opportunity. Hold a charged boost through the whole section and you''ll come out faster than anyone who tapped the trigger. The cherry-blossom section at the top is purely visual; don''t slow for it. Middleweights with high handling shine here. The tail-flick descent has a tighter line than it looks; favor the inside. Lakitu sounds calm here, which is rare for any anti-grav track. Race brave, friend.',
 'claude-handwritten'),

('Mute City',
 'Mute City is the fastest track in the rotation, and the magnetic strip is why. Stay on the blue stripes whenever the racing line allows — they recharge your boost. The big turn at the start is wider than it looks; commit. Mid-weights and heavies do well because top speed matters more than handling. There''s no item track here; collect coins instead and you''ll passively gain speed. The F-Zero machines passing through are cosmetic but distracting. Don''t stare. Trust your kart and just go. Off you go.',
 'claude-handwritten');

-- ── Triforce Cup ───────────────────────────────────────────────────────────
INSERT OR IGNORE INTO track_musings (track_name, body, model_used) VALUES
('Wii Wario''s Gold Mine',
 'Wii Wario''s Gold Mine is dim, narrow, and shorter than it feels. The mine cart section has fixed track — don''t try to dodge; just stay on the rails. The bat section in the middle scares first-timers but doesn''t hurt anything. Heavies do well here because the bumps don''t move them. The shortcut down the side passage is real and saves a full second if you''ve got a mushroom. The lighting changes between laps — Wario''s been fiddling with the wiring. Trust your line. Race well.',
 'claude-handwritten'),

('SNES Rainbow Road',
 'SNES Rainbow Road is the original and the cleanest of the bunch — three loops, no walls, no surprises. The chain chomps roll in a tight pattern; memorize their gaps. The ribbon-thin turns reward middleweights with tight handling; lights skid. There''s a beautiful inside line on the second loop if you''ve got a mushroom. The sky here is the bluest blue in the game, which is worth noticing for half a second. Don''t let nostalgia slow you down. Race clean. Off you go, friend.',
 'claude-handwritten'),

('Ice Ice Outpost',
 'Ice Ice Outpost runs as twin parallel tracks for most of the lap — the right side is faster, the left is safer. The ice patches aren''t random; they''re at the same beats every lap. Lights skate, heavies plow through. The bridge section where the tracks cross is where most overtakes happen; commit or hold back, don''t hover. Don''t bother trying to jump tracks mid-lap; you''ll just trade for nothing. Lakitu watches from the cold; he wears a scarf. Charming detail. Race smart, friend.',
 'claude-handwritten'),

('Hyrule Circuit',
 'Hyrule Circuit is a love letter to a different game, and the track designers know it. The big sword pull section in the middle is for the show — there''s no real shortcut there. The cobblestones slow heavies; cruisers and middleweights have the easier day. The Hylian crests around the track aren''t decorative — they''re item replacements, so collecting them matters. The fence jump at the finish line is for tricks only; don''t bother. Lakitu does a special call here. It''s worth the price of admission. Run brave.',
 'claude-handwritten');

-- ── Crossing Cup ───────────────────────────────────────────────────────────
INSERT OR IGNORE INTO track_musings (track_name, body, model_used) VALUES
('GCN Baby Park',
 'GCN Baby Park is the joke track that isn''t a joke. Seven laps on an oval the size of a parking lot means item chaos from start to finish. Don''t bother saving items; spend them. Stay outside on the corners; inside means trade paint with every racer behind you. Heavies do well because they don''t get bumped off. Lights get eliminated by item barrages. Most of the field finishes within a second of each other. It''s not a strategy race, it''s a survival race. Hold on tight.',
 'claude-handwritten'),

('GBA Cheese Land',
 'GBA Cheese Land is goofy and gentle until the cheese-wheel obstacles start rolling. They''re not random — they crest the hill on a beat. Stay inside on the cheese-wedge ramp; the outside loses you grip. Mid-weights with strong handling do well; lights get bumped by the wheels. The mouse hole shortcut on the back is real and worth taking if you''ve got a mushroom. The whole place smells like fondue when the wind shifts. Honestly delightful track. Race with a smile.',
 'claude-handwritten'),

('Wild Woods',
 'Wild Woods runs through and over a forest village — there are routes above the canopy and below it, and the upper route is faster but more dangerous. The wood platforms creak on lap two and the worst-built ones break by lap three; learn which to avoid. Lights and cruisers do best up high; heavies stay on the ground route. The vine swings are real boosts if you ride them low. Listen to the birds — they shift before a hazard. The whole track is alive. Go enjoy it, friend.',
 'claude-handwritten'),

('Animal Crossing',
 'Animal Crossing changes by season — the layout''s the same but the visuals (and a few hazards) shift between spring, summer, autumn, and winter. Spring''s the fastest because the rivers are low. The villagers crossing the road don''t really stop, so memorize the safe windows. Lights and cruisers tour the town best. Pippin''s got cousins in the village and they wave him through every time; he hasn''t picked up a single shell in his life racing here. He insists this is skill. Race with kindness. Have a good one.',
 'claude-handwritten');

-- ── Bell Cup ───────────────────────────────────────────────────────────────
INSERT OR IGNORE INTO track_musings (track_name, body, model_used) VALUES
('3DS Neo Bowser City',
 '3DS Neo Bowser City is a wet track that rewards patience. The neon road glistens; that''s not just visual — the surface really is slick. Take corners wider than instinct says. The big lap-three downpour reduces visibility; if you don''t have a mushroom in reserve, you''ll lose seconds. Mid-weights and heavies do well; lights hydroplane. The pedestrian bridges have a sneaky shortcut underneath them — the underpass — that''s worth taking once you''ve memorized the underwater section. Stay sharp through the rain. Don''t take it personal. Off you go.',
 'claude-handwritten'),

('GBA Ribbon Road',
 'GBA Ribbon Road runs through a child''s bedroom — it''s whimsical, and the ribbons are real obstacles. The pink ribbon section gives you a slight magnetic pull along the right edge; ride that. The toy block tower has a forgiving racing line up the middle, but the bumps reset your drift charge. Lights and cruisers thread the tight rooms best. The shortcut through the dollhouse is a meme — it''s there, but it loses time. Don''t bother. The whole track is a bedtime story put to wheels. Enjoy yourself.',
 'claude-handwritten'),

('Super Bell Subway',
 'Super Bell Subway has trains. Real, kart-killing trains. The tracks light up before a train comes through; that''s your warning. Cross the rails fast or wait — there''s no middle option. The main station section is wide and forgiving; the tunnels punish heavies. Mid-weights with strong handling have the best time. The sneaky platform shortcut on lap two saves you a beat if your mushroom is fresh. The ambient subway sounds mask item alerts; trust the minimap. Race smart. Off you go, friend.',
 'claude-handwritten'),

('Big Blue',
 'Big Blue is the second F-Zero track in the rotation and it''s the trickier of the two. The anti-grav sections wrap around the magnetic course like a spring; ride the inside lane through the curves. The water below isn''t an out-of-bounds — Lakitu pulls you fast — but it costs you positions. Mid-weights and heavies for speed; cruisers can handle the tight twists. The boost strips are everywhere; chain them. Pippin has a small betting pool on the Blue Falcon every race; he loses most weeks. Race fast and don''t bet.',
 'claude-handwritten');

-- ── Golden Dash Cup (Booster) ──────────────────────────────────────────────
INSERT OR IGNORE INTO track_musings (track_name, body, model_used) VALUES
('Tour Paris Promenade',
 'Tour Paris Promenade is wide, French, and prettier than it is technical. The roundabout under the Arc has six entries and exits; pick a lane on the approach and stay committed. The cobblestone sections slow heavies more than cruisers. The bridge over the Seine is a forgiving boost ramp; ride it center. Lights with strong drift have the easiest race here. The accordion music in the background is delightful but masks item alerts. The whole track feels like a postcard you sped through. Race with style, friend.',
 'claude-handwritten'),

('3DS Toad Circuit',
 '3DS Toad Circuit is the friendly cousin of every other Mario circuit — straight stretches, gentle turns, no real hazards. That doesn''t mean it''s a free win; the simplicity is the trap. Every corner is a place where someone might out-drift you. Stay smooth, stay on the racing line. Mid-weights win cleanly here. The big banner with Toad''s face is the only landmark you really need. Lakitu sounds bored at the finish, which is fair. Polish your fundamentals here. Off you go.',
 'claude-handwritten'),

('N64 Choco Mountain',
 'N64 Choco Mountain is the old track that hasn''t lost any of its bite. The falling rocks aren''t random — they roll on a count from the start of the lap. Stay middle through the wall-less section; the outside drops you off the mountain. Heavies handle the bumps best, but they struggle on the chicane at the summit. Mid-weights are the safe pick. The chocolate texture is the joke; everything else is serious. Race with respect. Off you go, friend.',
 'claude-handwritten'),

('Wii Coconut Mall',
 'Wii Coconut Mall is a glorious chaos of escalators, parked cars, and shop signage. The escalator section can be ridden up or down — up is faster but you give up a defensive position. The cars in the parking lot used to be random; now they''re patterned, so memorize them. Cruisers and lights do best in the tight mall hallways. Pippin worked at the food court here one summer and still talks about it; he claims he met Daisy. He didn''t. Race well and grab a smoothie after. Off you go.',
 'claude-handwritten');

-- ── Lucky Cat Cup (Booster) ────────────────────────────────────────────────
INSERT OR IGNORE INTO track_musings (track_name, body, model_used) VALUES
('Tour Tokyo Blur',
 'Tour Tokyo Blur runs through a neon-bright Tokyo and it''s a flat track that rewards cleanliness. The Shibuya-style intersection has multiple paths; the left is faster but tighter. Mid-weights cruise here. The dragon-decorated arch is a tight squeeze for heavies; favor the right entry. The shortcut down the alleyway is for the brave and the boosted. Don''t stare at the signs; they''re masking the item cues. Lakitu sounds especially polite here. Have a smooth, sushi-clean race, friend.',
 'claude-handwritten'),

('DS Shroom Ridge',
 'DS Shroom Ridge is a winding country road with real traffic — cars, trucks, the lot. They''re not random; they run a loop. Stay tucked between two trucks for the slipstream, then pop out on the inside of a bend. Cruisers do well here; the long curves reward steady handling. The mushroom-shaped boulders are decorative. Pippin once tried to hitchhike on this road and got picked up by a Boo, which sent him home early. Don''t take rides from strangers. Race steady. Off you go.',
 'claude-handwritten'),

('GBA Sky Garden',
 'GBA Sky Garden floats above the clouds and it''s one of the gentler tracks in the rotation. The flower-petal jumps boost you if you ride them centered. The narrow cloud-bridges have no walls, so a bump from a careless heavy ends your race quick. Lights and middleweights thrive; heavies fall off. The shortcut over the gap costs you nothing if you''ve got a mushroom. The sky here is the lightest blue you''ll see. Stay smooth, stay floating. Enjoy it, friend.',
 'claude-handwritten'),

('Tour Ninja Hideaway',
 'Tour Ninja Hideaway is the most layered of the Tour tracks — it has real upper and lower routes, and the upper is faster if you can take it. The bamboo forest section narrows your line; favor the inside. The roof tiles section rewards a centered run. Cruisers and lights are best here; heavies are too wide for the secret passages. Watch for shuriken-style hazards; they''re not damaging but they break your boost charge. The whole place feels like a Kurosawa frame. Race with respect. Off you go.',
 'claude-handwritten');

-- ── Turnip Cup (Booster) ───────────────────────────────────────────────────
INSERT OR IGNORE INTO track_musings (track_name, body, model_used) VALUES
('Tour New York Minute',
 'Tour New York Minute is loud and fast and the taxis are real obstacles. They have a route; learn it. The Times Square section is wide and forgiving — that''s where heavies catch up to lights. The shortcut through the alley between buildings is real but the trash piles are a hazard. Mid-weights and heavies do well; lights get pushed around by traffic. The blimp overhead is purely visual. Trust the minimap, not the chaos. Race like you''ve got somewhere to be. Off you go.',
 'claude-handwritten'),

('SNES Mario Circuit 3',
 'SNES Mario Circuit 3 is one of the oldest tracks in the rotation and it has all the original charm. Three laps, simple layout, low ceiling on what you can do beyond a clean line. The pipes are decoration; don''t try to enter them. Mid-weights with strong drift do best. The boost pad on the back straight isn''t a guarantee — you have to hit it square. Lakitu sounds nostalgic here. So do most racers. Run a polite race. Off you go.',
 'claude-handwritten'),

('N64 Kalimari Desert',
 'N64 Kalimari Desert is the train track. The train crosses the road on a loop; cross when you can hear it but not see it. The cactus section is more forgiving than in the original — but they still bump you off-line. Stay center. Heavies plow through; lights skid. The shortcut on the train tracks themselves is real — ride between the rails — but if a train comes, you lose. The whole desert smells like dry copper at sunset. Mind the train. Race smart.',
 'claude-handwritten'),

('DS Waluigi Pinball',
 'DS Waluigi Pinball runs you through a giant pinball machine and yes, you can get caught by the bumpers. They''re patterned; learn the rhythm. The big ball on lap two is the genuine hazard — it''ll launch you. Stay middle until you see it move. Mid-weights with strong handling fare best; the bumpers love heavies and lights both. The neon track is gorgeous, the music is a banger. Don''t get distracted. Race with bravado. Off you go.',
 'claude-handwritten');

-- ── Propeller Cup (Booster) ────────────────────────────────────────────────
INSERT OR IGNORE INTO track_musings (track_name, body, model_used) VALUES
('Tour Sydney Sprint',
 'Tour Sydney Sprint runs past the Opera House and through the harbor; it''s a wide, friendly track. The big bend around the bay is where everyone overcommits; lift early. The shell-shaped Opera House section has a forgiving line up the middle. Cruisers do well here. The ferry section has hidden coins worth grabbing on lap two. Lakitu sounds especially friendly at this finish, like he had a flat white before the race. Race relaxed. Off you go.',
 'claude-handwritten'),

('GBA Snow Land',
 'GBA Snow Land is short, slick, and easy to underestimate. The ice patches are at the corners; brake before, not during. The snowmen are decorative, but the snowball obstacle on lap two is real. Heavies plow through; lights skate. Mid-weights with off-road traction are the best pick. The shortcut through the snowdrift is a gamble — sometimes it has a shroom in it, sometimes nothing. The whole track feels like a holiday card. Bundle up and race. Off you go.',
 'claude-handwritten'),

('Wii Mushroom Gorge',
 'Wii Mushroom Gorge is built around the mushroom-bounce mechanic — every shroom on the track is a small boost, but only if you land centered. The gorge crossing in the middle is the trickiest part; pick the right line of mushrooms or you''ll fall short. Lights bounce highest; heavies struggle to land on the smaller shrooms. The shortcut through the cave is real and saves significant time. Race with rhythm. The whole place feels like an old hike, doesn''t it? Enjoy it, friend.',
 'claude-handwritten'),

('Sky-High Sundae',
 'Sky-High Sundae is the silliest track in the rotation — you''re racing on actual ice cream sundaes. The whipped cream section is slick; treat it like the ice tracks. The cherry on top is the high point; the descent from it is fast and forgiving. Lights and middleweights thrive in the soft texture. Pippin nearly cried when he first raced here — he thought he was supposed to eat it. He''s been told otherwise but he still licks his fingers after every race. Have a sweet race, friend.',
 'claude-handwritten');

-- ── Rock Cup (Booster) ─────────────────────────────────────────────────────
INSERT OR IGNORE INTO track_musings (track_name, body, model_used) VALUES
('Tour London Loop',
 'Tour London Loop runs past Big Ben and across Tower Bridge — it''s a long, wide track with a few sharp surprises. The bridge raises on lap two; if you don''t have a mushroom, take the side route. Cruisers and middleweights have the best day. The double-decker buses are real obstacles; treat them like trucks. The Westminster section has a small boost ribbon along the inside. Lakitu does his very best impression of an accent here. Genuinely funny. Race with dignity, friend.',
 'claude-handwritten'),

('GBA Boo Lake',
 'GBA Boo Lake is a foggy, gentle track with mean-spirited Boos that will yank you off-line if you make eye contact — figuratively. The shortcut over the lake is real but the wood planks are rotted on lap two. Cruisers handle the haunted twists best. The boos themselves are decorative until lap three, when one actively chases the leader. Stay close to second place if you can hear it. The whole track feels like a Halloween night. Race with a smile. Off you go.',
 'claude-handwritten'),

('3DS Rock Rock Mountain',
 '3DS Rock Rock Mountain is a steep climb with narrow ledges and falling boulders. The boulders aren''t decorative — they roll on a beat from the top. Memorize the gaps. Heavies plow up the climb best; lights get pushed around by the wind. The shortcut along the cliff face is real but the margin is tiny; only commit if you''ve already memorized it. The view from the summit is gorgeous; don''t look. Race with nerve. Off you go.',
 'claude-handwritten'),

('Wii Maple Treeway',
 'Wii Maple Treeway is one of the prettier tracks and one of the trickier too — the leaf piles are real hazards that hide bumps and boost pads. Stay on the racing line. The big swing-set section gives you a boost if you ride the rope-bridge centered. Cruisers and middleweights do best in the autumn turns. The shortcut over the gap with the long jump is worth taking if you''ve got a mushroom. The wind smells like wet leaves. Race well, friend.',
 'claude-handwritten');

-- ── Moon Cup (Booster) ─────────────────────────────────────────────────────
INSERT OR IGNORE INTO track_musings (track_name, body, model_used) VALUES
('Tour Berlin Byways',
 'Tour Berlin Byways is a modern city track with wide boulevards and a few cramped alleys. The big sweeping curve past the Brandenburg Gate is forgiving; ride the inside. The tram tracks slow heavies; lights and cruisers cross them cleanly. The shortcut through the underpass costs you nothing if you''ve got a fresh boost. The architecture is honestly worth a glance, which you can''t afford. Don''t. Race straight. Off you go.',
 'claude-handwritten'),

('DS Peach Gardens',
 'DS Peach Gardens is a polite, regal track that''s tighter than it looks. The hedge maze has a clear racing line if you''ve taken a lap to learn it. The chain chomp wandering the central garden is on a loop; he doesn''t see well, but he does see. Stay clear. Cruisers do best here; the corners reward steady handling. Mid-weights are fine too. The flower bed shortcut is a trap; it loses time. Stay on the path. Race politely, friend.',
 'claude-handwritten'),

('Tour Merry Mountain',
 'Tour Merry Mountain is wrapped in holiday cheer — snow, lights, presents — and races faster than it looks. The big ramp at the start lets you trick if your boost is fresh; commit. The ice section in the middle is the real obstacle; brake before, not during. Lights and cruisers handle the snow best. Pippin set up a hot cocoa stand at the gondola here last December and actually made a profit, for once. He''s been insufferable about it since. Race warm. Off you go.',
 'claude-handwritten'),

('3DS Rainbow Road',
 '3DS Rainbow Road is the lounger of the rainbow road family — wider lanes, gentler curves, fewer surprises. The anti-grav sections are well-marked; ride the inside. The narrow ribbon descents have walls now, so a bump won''t kill you. Mid-weights are the sweet spot. The shortcut over the satellite is real and saves significant time. Lakitu seems to enjoy this track most of all the rainbow roads. Run it with confidence. Off you go.',
 'claude-handwritten');

-- ── Fruit Cup (Booster) ────────────────────────────────────────────────────
INSERT OR IGNORE INTO track_musings (track_name, body, model_used) VALUES
('Tour Amsterdam Drift',
 'Tour Amsterdam Drift runs along the canals and over the bridges, and it rewards patience. The big canal-side curve is wider than instinct says; don''t brake into it, drift through it. The flower market section is wide and forgiving; that''s where heavies catch up. The shortcut over the bridge railing is real but the landing is tight. Cruisers and middleweights have the best race. The bikes lining the canals are decoration but distracting. Lakitu sounds like he wishes he had a coffee. Race smooth, friend.',
 'claude-handwritten'),

('GBA Riverside Park',
 'GBA Riverside Park is gentle in the middle and mean at the corners. The river section forces you onto a narrow bridge; pick your line early. The wood ramp gives you a boost if you ride it centered. Cruisers and lights thrive; heavies bottom out on the bumpy bits. The shortcut along the riverbank is real but the mud slows you. The whole track is shorter than it feels. Race patient. Off you go.',
 'claude-handwritten'),

('Wii DK Summit',
 'Wii DK Summit is the half-pipe track — the big snowboard descent in the middle is the whole point. Ride the inside curve down; the outside drops you off the cliff. Lights and cruisers carve the descent best. The cannon at the start launches you to the summit; just hold on. The shortcut at the bottom through the trees is real and saves a half-second. The wind smells like fir and fresh powder. Race with style. Off you go.',
 'claude-handwritten'),

('Yoshi''s Island',
 'Yoshi''s Island looks like a children''s book and races like one too — but the friendly art hides some serious hazards. The shy guy section has them throwing dice at you; weave through them. The egg ramp jump is forgiving if you hit it centered. Cruisers and middleweights thrive in the soft pastel turns. The shortcut over the egg-shaped boulder is a trap; loses time. Lakitu sounds delighted at the finish here. Race with joy, friend.',
 'claude-handwritten');

-- ── Boomerang Cup (Booster) ────────────────────────────────────────────────
INSERT OR IGNORE INTO track_musings (track_name, body, model_used) VALUES
('Tour Bangkok Rush',
 'Tour Bangkok Rush is loud, narrow, and full of small market stalls that count as obstacles. The tuk-tuks aren''t random; they have a route. Stay on the racing line. The temple section has a forgiving wide curve; that''s where overtakes happen. Cruisers and lights do best in the tight market alleys. The shortcut through the river is real but cold. The whole track smells like street food in the best possible way. Race fast and have a noodle after. Off you go.',
 'claude-handwritten'),

('DS Mario Circuit',
 'DS Mario Circuit is the safest, easiest Mario circuit in the rotation — short laps, gentle turns, minimal hazards. The boost pads on the back straight are reliable. Mid-weights with strong drift do best. The shortcut over the kerb on lap two is real and saves a beat. Lakitu sounds bored here, which is fair. Polish your fundamentals; this is the track to practice on. Race clean. Off you go.',
 'claude-handwritten'),

('GCN Waluigi Stadium',
 'GCN Waluigi Stadium is Waluigi''s vanity project too, and it''s mean about it. The fire rings on lap two are a guaranteed hazard if you don''t read them; they fire on a beat. The mud section slows heavies less than you''d expect. The big jump in the middle is forgiving — trick off it for the boost. Mid-weights and heavies do best here. The shortcut through the fire is brave and probably not worth it. Race bold. Off you go.',
 'claude-handwritten'),

('Tour Singapore Speedway',
 'Tour Singapore Speedway runs along the bay and through the financial district at night. The lights are spectacular and distracting; trust the racing line, not the skyline. The big sweeping curve past the Marina Bay is wider than instinct says. Mid-weights and cruisers do best. The shortcut through the tunnel saves a beat if your boost is fresh. The track''s smooth as anything you''ll race on. Race relaxed. Off you go.',
 'claude-handwritten');

-- ── Feather Cup (Booster) ──────────────────────────────────────────────────
INSERT OR IGNORE INTO track_musings (track_name, body, model_used) VALUES
('Tour Athens Dash',
 'Tour Athens Dash runs through the old city and past the Parthenon — wide stone roads, gentle turns, minimal real hazards. The donkey carts on the route are stationary obstacles; learn their spots. The shortcut up the marble steps is real but the bumpy landing slows you. Cruisers and middleweights have the best race. The shadow of the temple stretches across the track at sunset — gorgeous and distracting. Lakitu does his best impression of an ancient herald. Race with reverence. Off you go.',
 'claude-handwritten'),

('GCN Daisy Cruiser',
 'GCN Daisy Cruiser races on a moving ship — the whole track tilts as you go. The pool section in the middle has a current that pushes you starboard; lean port. The deck chairs are obstacles. Mid-weights have the best handling for the tilt. The shortcut through the kitchen is real and saves a beat if you don''t slip on the spilled food. Pippin worked the ship''s galley for a summer and still has dreams about Daisy''s tableware. Don''t ask. Race smooth. Off you go.',
 'claude-handwritten'),

('Wii Moonview Highway',
 'Wii Moonview Highway is a night-time highway with real traffic — buses, cars, the lot. The headlights help you see them coming; use that. The big sweeping curves are forgiving but the slipstream behind a bus is real money. Cruisers and heavies do best. The shortcut on the overpass costs you nothing if your mushroom is fresh. The moon over the bay is gorgeous, which is why the track exists. Race long. Off you go.',
 'claude-handwritten'),

('Squeaky Clean Sprint',
 'Squeaky Clean Sprint races through a giant bathroom — the soap section is slick, the sponge section bumpy, and the water section forces a precise line. The rubber duckies are decorative. The toothbrush bristles slow you down; ride the gaps. Lights and middleweights thrive in the soapy chaos. The shortcut through the drain is a real risk-reward — fast if you survive, slow if you don''t. Race with humor. Off you go.',
 'claude-handwritten');

-- ── Cherry Cup (Booster) ───────────────────────────────────────────────────
INSERT OR IGNORE INTO track_musings (track_name, body, model_used) VALUES
('Tour Los Angeles Laps',
 'Tour Los Angeles Laps runs from the beach into Hollywood and back — wide boulevards, palm trees, gentle curves. The big freeway section is where slipstream matters; tuck behind another kart and ride the draft. Cruisers do best here. The shortcut through the studio lot is real and worth taking on lap two. The traffic isn''t aggressive but it''s persistent. Race smooth. Lakitu sounds like he''s heading to a smoothie. Off you go.',
 'claude-handwritten'),

('GBA Sunset Wilds',
 'GBA Sunset Wilds is the original and beautifully colored — and the sky actually darkens between laps, which is fun and disorienting. The shy guy huts are real obstacles. Stay center through the village. Mid-weights with strong handling do best. The shortcut along the dunes is a slow track full of sand; not worth it. The wind smells like sun-warmed sage. Race patient. Off you go.',
 'claude-handwritten'),

('Wii Koopa Cape',
 'Wii Koopa Cape is built around the underwater tube section in the middle — fast and forgiving, but the wall-jump shortcut at the entry saves real time if you''ve got a mushroom. The waterfall ramp at the start is forgiving; ride it centered. Cruisers and middleweights do best in the wet curves. The shortcut over the wood bridge is real. The tropical music will get stuck in your head. Race with rhythm. Off you go.',
 'claude-handwritten'),

('Tour Vancouver Velocity',
 'Tour Vancouver Velocity runs from the city into the mountains and back — long, varied, and rewarding. The bridge over the bay is wide and forgiving. The mountain section narrows; cruisers and middleweights thrive. The shortcut through the totem-pole park is real. Heavies struggle on the alpine descent. The whole track smells like cedar and ocean. Race patient. Off you go, friend.',
 'claude-handwritten');

-- ── Acorn Cup (Booster) ────────────────────────────────────────────────────
INSERT OR IGNORE INTO track_musings (track_name, body, model_used) VALUES
('Tour Rome Avanti',
 'Tour Rome Avanti runs around the Colosseum and through ancient streets. The cobblestones slow heavies more than anyone. The fountain in the middle of the lap is a real hazard — the water blocks your view. The shortcut through the cathedral is real but tight. Cruisers and middleweights have the best day here. Lakitu sounds genuinely impressed by the architecture. Race respectful. Off you go.',
 'claude-handwritten'),

('Wii Daisy Circuit',
 'Wii Daisy Circuit runs through a coastal European town at dusk. The big lighthouse at the start is the landmark; everything routes off it. The cobblestones slow heavies; cruisers and lights handle them best. The shortcut through the fountain plaza saves a beat. The whole track has the warmest light in the game. Race smooth. Off you go.',
 'claude-handwritten'),

('Piranha Plant Cove',
 'Piranha Plant Cove is the meanest of the cove-style tracks — the piranhas snap on a real pattern and they bite. Memorize the safe windows. The shortcut through the underwater section is real but the current slows you. Mid-weights with strong handling do best. The boost pads through the kelp are reliable if you ride them centered. Race brave. Off you go, friend.',
 'claude-handwritten'),

('Tour Madrid Drive',
 'Tour Madrid Drive runs through Madrid''s plazas and past its grand buildings. The big bullring section has a forgiving wide curve. The cobblestones slow heavies. The shortcut through the alley is real but tight. Mid-weights and cruisers have the best race. Lakitu does his very best Spanish accent here — it''s worth the price of admission. Race with passion. Off you go.',
 'claude-handwritten');

-- ── Spiny Cup (Booster) ────────────────────────────────────────────────────
INSERT OR IGNORE INTO track_musings (track_name, body, model_used) VALUES
('3DS Rosalina''s Ice World',
 '3DS Rosalina''s Ice World is the gentle ice track — cleaner ice than Sherbet, fewer freezies, more forgiving turns. The big ice slide in the middle is a guaranteed boost if you ride it centered. The penguin section is decorative. Lights and middleweights handle the surface best. The shortcut through the cave is real and saves a beat. The cold smells like rosemary and pine here, somehow. Race smooth. Off you go.',
 'claude-handwritten'),

('SNES Bowser Castle 3',
 'SNES Bowser Castle 3 is one of the original Bowser castle tracks and it shows — sharp turns, lava pits, no anti-grav, no apologies. The Thwomps are at fixed positions; memorize them. The shortcut over the lava is real but requires a mushroom and nerve. Heavies do well here because the bumps don''t move them. Mid-weights are also fine. Lakitu sounds tired here — he''s been pulling kids out of lava for thirty years. Race respectful. Off you go.',
 'claude-handwritten'),

('Wii Rainbow Road',
 'Wii Rainbow Road is the friendliest of the rainbow roads — wider lanes, smaller drops, more boost pads. The big drop in the middle is forgiving if you hit it centered. The narrow ribbon at the end is shorter than it looks. Mid-weights cruise here. Pippin runs a telescope stand at the start gate and spots half the racers'' family members in the crowd, which makes them nervous. He thinks it''s helpful. It is not. Race smooth, friend. Off you go.',
 'claude-handwritten'),

('GCN DK Mountain',
 'GCN DK Mountain launches you up the mountain in a cannon and then races you back down. The descent is the whole track. The boulder section near the start has them rolling on a beat. Heavies plow through; lights get pushed around. The shortcut through the trees is real and saves a half-second. Mid-weights are the safest pick. The view from the cannon''s apex is honestly worth a peek, which you can''t afford. Race brave. Off you go.',
 'claude-handwritten');

COMMIT;

-- ============================================================================
-- Done. 96 musings, ~12 Pippin cameos sprinkled across the cups.
-- ============================================================================
