# Scoring Systems - Quick Reference

## 🎯 Available Systems

| Icon | System | Best For | Max Score | Configuration |
|------|--------|----------|-----------|---------------|
| 🌟 | **Pre-Season** | Off-season casual play | N/A | None needed |
| 📊 | **Average + Attendance** | Consistency + participation | ~70 | Attendance weight, caps, drops |
| 🏆 | **Cup-Based** | Variety + clear goals | 720 (12) / 1440 (24) | 12 or 24 cups |
| ⭐ | **Best N GPs** | Pure performance | 900 (N=15) | N value (default 15) |
| 🗑️ | **Drop Worst** | Forgiving variety | 600 (12-2) | Cups + drop count |
| 💎 | **Perfect Hunt** | Excellence focus | 1440+ | Cups + multiplier |
| 🎲 | **Random Draw** | Fair competition | Variable | Auto-assigned |

---

## 📐 Calculation Formulas

### 🌟 Pre-Season
```
scores = all GP scores sorted ascending
drop = floor(count * 0.1)
result = average(scores after dropping worst 10%)
```

### 📊 Average + Attendance
```
scores = all GP scores sorted ascending
drops = floor(count / drop_rate)
average = mean(scores after drops)
attendance = sum(weekly_capped_bonuses)
result = average + attendance
```

### 🏆 Cup-Based
```
for each cup in required_cups:
    best_scores[] = max(scores for cup)
result = sum(best_scores)
```

### ⭐ Best N GPs
```
scores = all GP scores sorted descending
result = sum(top N scores)
```

### 🗑️ Drop Worst
```
cup_scores = [best score per cup]
sorted = cup_scores sorted ascending
result = sum(sorted after dropping N worst)
```

### 💎 Perfect Hunt
```
for each cup:
    score = best cup score
    if score == 60:
        total += (score * multiplier)
    else:
        total += score
result = total
```

### 🎲 Random Draw
```
assigned_cups = random assignment for racer
for each cup in assigned_cups:
    total += best cup score
result = total
```

---

## 🔧 Admin Quick Actions

### Create New Season
1. Go to `/admin/seasons`
2. Scroll to "➕ Create New Season"
3. Fill in:
   - Season ID (auto: s02, s03, etc.)
   - Season Name (e.g., "Autumn 2026")
   - Term (Spring 21w / Autumn 19w)
   - Year (2026)
   - Scoring System (select from 7)
4. Configure system-specific settings
5. Click "✨ Create Season"

### Create Pre-Season
```
ID: ps01 or s01.5
Name: "Summer Break 2026"
Term: (leave blank or custom)
Scoring: Pre-Season
Dates: Week 23-31
```

### Edit Existing Season
1. Find season card
2. Modify fields
3. Click "💾 Save Configuration"

### Archive Season
1. Click "📦 Finalize & Archive"
2. Auto-generates Hall of Fame
3. Season becomes read-only

---

## 📊 When to Use Each System

### 🌟 Pre-Season
**Use when:**
- Between official seasons
- Summer/Winter break
- Casual low-stakes play

**Duration:** 2-9 weeks

### 📊 Average + Attendance
**Use when:**
- Encouraging participation
- Rewarding consistency
- Traditional season format

**Duration:** 19-21 weeks

### 🏆 Cup-Based
**Use when:**
- Want variety enforcement
- Clear completion goals
- Strategic retry mechanics

**Duration:** 19-21 weeks (0.6-1 cup/week)

### ⭐ Best N GPs
**Use when:**
- Simple, clear rules
- High GP volume expected
- Performance over variety

**Duration:** Any (flexible)

### 🗑️ Drop Worst
**Use when:**
- Want variety but be forgiving
- Allow "bad cup" redemption
- Reduce RNG frustration

**Duration:** 19-21 weeks

### 💎 Perfect Hunt
**Use when:**
- Rewarding skill/excellence
- Creating "perfect chase" drama
- High skill ceiling desired

**Duration:** 19-21 weeks

### 🎲 Random Draw
**Use when:**
- Eliminating meta gaming
- Ensuring fairness
- Testing adaptability

**Duration:** 19-21 weeks

---

## 🎨 UI Helper Functions

```php
// Get racer's score for season
$score = calculateGPScore($pdo, $racer_id, $season_id);

// Get cup progress (for cup-based seasons)
$progress = getCupProgress($pdo, $racer_id, $season_id, 12);

// Get scoring system info
$info = getScoringSystemInfo($pdo, $season_id);

// Get detailed breakdown
$breakdown = getScoringBreakdown($pdo, $racer_id, $season_id);
```

---

## 🗓️ UiB Calendar Integration

### Spring Season
- **Duration:** 21 weeks
- **Weeks:** 2-22
- **Dates:** Early Jan → Early Jun
- **Finals:** Week 21
- **Grace:** Week 22

### Autumn Season
- **Duration:** 19 weeks
- **Weeks:** 33-51
- **Dates:** Mid Aug → Mid Dec
- **Finals:** Week 50
- **Grace:** Week 51

### Off-Season (Pre-Season)
- **Summer:** Weeks 23-31 (9 weeks)
- **Winter:** Weeks 52-1 (2 weeks)

---

## 🏆 Recommended Rotation

```
S1 (Spring 2026)    📊 Average + Attendance
PS1 (Summer 2026)   🌟 Pre-Season
S2 (Autumn 2026)    🏆 Cup-Based (12)
S3 (Spring 2027)    🗑️ Drop Worst (12, drop 2)
PS2 (Summer 2027)   🌟 Pre-Season
S4 (Autumn 2027)    ⭐ Best 15 GPs
S5 (Spring 2028)    💎 Perfect Hunt (2x)
PS3 (Summer 2028)   🌟 Pre-Season
S6 (Autumn 2028)    🎲 Random Draw
```

---

## 🔑 Key Database Fields

```sql
season_meta.scoring_system
  - 'preseason'
  - 'average_attendance'
  - 'cup_based'
  - 'best_n_gps'
  - 'drop_worst'
  - 'perfect_hunt'
  - 'random_cup_draw'

season_meta.cups_required (for cup systems)
  - 12 (base cups)
  - 24 (base + DLC)

season_meta.best_n_count (for best_n_gps)
  - Default: 15

season_meta.drop_worst_count (for drop_worst)
  - Default: 2

season_meta.perfect_multiplier (for perfect_hunt)
  - Default: 2.0
```

---

## 📈 Score Ranges by System

| System | Typical Min | Typical Max | Notes |
|--------|-------------|-------------|-------|
| Pre-Season | 45 | 58 | Simple average |
| Avg + Attendance | 50 | 75 | Depends on participation |
| Cup-Based (12) | 400 | 720 | Sum of 12 cups |
| Best 15 GPs | 700 | 900 | Top 15 scores |
| Drop Worst (10) | 450 | 600 | Best 10 of 12 |
| Perfect Hunt | 600 | 1200+ | With 2x multiplier |
| Random Draw | Variable | Variable | Depends on assignments |

---

## ✅ Testing Commands

```bash
# Check syntax
php -l private/includes/gp_logic.php
php -l public_html/admin/seasons.php

# Test scoring calculation (create test script)
php test_scoring.php

# View season metadata
sqlite3 private/data/league.db "SELECT * FROM season_meta;"

# Test cup progress
# (Run via browser at /test/cup_progress.php)
```
