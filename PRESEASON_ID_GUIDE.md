# Pre-Season ID Guide

## ✅ Recommended Format: `ps##`

### **Why `ps##`?**
- Clear distinction from official seasons (`s##`)
- Easy to filter in queries (`WHERE gpid LIKE 'ps%'`)
- Chronological numbering (ps01, ps02, ps03)
- Simple pattern matching
- Intuitive naming

---

## 📅 Chronological Example

```
s00  → Season 0 (Autumn 2025)
s01  → Season 1 (Spring 2026, Week 2-22)
ps01 → Pre-Season 1 (Summer 2026, Week 23-31)
s02  → Season 2 (Autumn 2026, Week 33-51)
ps02 → Pre-Season 2 (Winter 2026-27, Week 52-1)
s03  → Season 3 (Spring 2027, Week 2-22)
ps03 → Pre-Season 3 (Summer 2027, Week 23-31)
s04  → Season 4 (Autumn 2027, Week 33-51)
```

---

## 🎯 Pre-Season Naming Examples

### **Summer Pre-Seasons (Between Spring and Autumn)**
```sql
season_id: ps01
season_name: "Summer Break 2026"
academic_term: NULL (or "summer")
academic_year: 2026
start_week: 23
end_week: 31
start_date: 2026-06-02
end_date: 2026-08-09
scoring_system: preseason
status: active
```

### **Winter Pre-Seasons (Between Autumn and Spring)**
```sql
season_id: ps02
season_name: "Winter Break 2026-27"
academic_term: NULL (or "winter")
academic_year: 2026
start_week: 52
end_week: 1
start_date: 2026-12-21
end_date: 2027-01-03
scoring_system: preseason
status: active
```

---

## 🔧 Implementation

### **Admin Interface Updates**
✅ Auto-increment now tracks both `s##` and `ps##`
✅ "Create New Season" has toggle for Official vs Pre-Season
✅ Pre-Season button auto-fills:
   - ID: ps01, ps02, etc.
   - Scoring: preseason
   - Term: NULL/empty
   - Name: "Summer Break YYYY"

### **Database Queries**

```sql
-- Get all official seasons
SELECT * FROM season_meta WHERE season_id LIKE 's%' AND season_id NOT LIKE 'ps%';

-- Get all pre-seasons
SELECT * FROM season_meta WHERE season_id LIKE 'ps%';

-- Get all seasons chronologically (mix of both)
SELECT * FROM season_meta ORDER BY season_id;
```

### **GP Results Filtering**

```sql
-- Results for official Season 1
SELECT * FROM results WHERE gpid LIKE 's01%';

-- Results for Pre-Season 1
SELECT * FROM results WHERE gpid LIKE 'ps01%';

-- All regular season results (exclude pre-season)
SELECT * FROM results WHERE gpid LIKE 's%' AND gpid NOT LIKE 'ps%';
```

---

## 📊 GP ID Format

### **Official Season GPs**
```
s01gp01 → Season 1, GP 1
s01gp02 → Season 1, GP 2
s02gp01 → Season 2, GP 1
```

### **Pre-Season GPs**
```
ps01gp01 → Pre-Season 1, GP 1
ps01gp02 → Pre-Season 1, GP 2
ps02gp01 → Pre-Season 2, GP 1
```

**Pattern:** `{season_id}gp{number}`

---

## 🎨 Display Examples

### **Homepage Dropdown**
```html
<select name="season">
    <option value="s02">Season 02 (Autumn 2026)</option>
    <option value="ps01">Pre-Season (Summer 2026)</option>
    <option value="s01">Season 01 (Spring 2026)</option>
    <option value="s00">Season 00 (Autumn 2025)</option>
</select>
```

### **Season Archives**
```
┌─────────────────────────────────────────┐
│ 🌟 Pre-Season 1 (Summer 2026)          │
│    Status: Active                       │
│    Scoring: Pre-Season (10% drop)       │
└─────────────────────────────────────────┘

┌─────────────────────────────────────────┐
│ 🏆 Season 2 (Autumn 2026)               │
│    Status: Upcoming                     │
│    Scoring: Cup-Based (12 cups)         │
└─────────────────────────────────────────┘
```

---

## ⚙️ Configuration Recommendations

### **Summer Pre-Season (9 weeks)**
```php
season_id: ps01
season_name: "Summer Break 2026"
scoring_system: preseason
start_week: 23
end_week: 31
duration: 9 weeks
typical_gps: 10-20
```

### **Winter Pre-Season (2 weeks)**
```php
season_id: ps02
season_name: "Winter Break 2026-27"
scoring_system: preseason
start_week: 52
end_week: 1
duration: 2 weeks
typical_gps: 3-6
```

---

## 🚫 What NOT to Use

### ❌ Avoid Decimal Format
```
s01.5 → Hard to query, unusual pattern
s02.5 → Breaks sorting, weird in URLs
```

### ❌ Avoid Suffix Format
```
s01p → Ambiguous ordering
s02p → Unclear relationship
```

### ❌ Avoid Mixed Prefixes
```
pre01 → Inconsistent with ps01
off01 → Too verbose
```

---

## 📝 SQL Examples

### **Create Pre-Season 1**
```sql
INSERT INTO season_meta (
    season_id,
    status,
    scoring_system,
    season_name,
    season_description,
    start_date,
    end_date,
    start_week,
    end_week
) VALUES (
    'ps01',
    'active',
    'preseason',
    'Summer Break 2026',
    'Casual off-season play with simple scoring',
    '2026-06-02',
    '2026-08-09',
    23,
    31
);
```

### **Activate Pre-Season**
```sql
-- Archive Spring season
UPDATE season_meta SET status = 'archived' WHERE season_id = 's01';

-- Activate pre-season
UPDATE season_meta SET status = 'active' WHERE season_id = 'ps01';
```

---

## 🎯 Benefits of `ps##` Format

### **Database Queries**
✅ Easy filtering: `LIKE 'ps%'`
✅ Clear separation from official seasons
✅ Chronological ordering when sorted

### **User Interface**
✅ Obvious distinction (ps vs s)
✅ Easy to communicate ("PS-oh-one")
✅ Clear in dropdowns and lists

### **Code Maintenance**
✅ Simple regex: `/^ps(\d+)$/`
✅ Consistent pattern matching
✅ No special cases needed

### **Future Flexibility**
✅ Can have multiple per year (ps01, ps02, ps03)
✅ Room for 99+ pre-seasons (ps01-ps99)
✅ No naming conflicts

---

## 🔄 Migration from Other Formats

If you started with a different format, easy to migrate:

### **From `s##.5` to `ps##`**
```sql
UPDATE season_meta SET season_id = 'ps01' WHERE season_id = 's01.5';
UPDATE results SET gpid = REPLACE(gpid, 's01.5', 'ps01');
UPDATE recap_archive SET season_id = 'ps01' WHERE season_id = 's01.5';
```

### **From `s##p` to `ps##`**
```sql
UPDATE season_meta SET season_id = 'ps01' WHERE season_id = 's01p';
UPDATE results SET gpid = REPLACE(gpid, 's01p', 'ps01');
UPDATE recap_archive SET season_id = 'ps01' WHERE season_id = 's01p';
```

---

## 📊 Summary

**Use `ps##` for pre-seasons:**
- ps01, ps02, ps03, etc.
- Clear, simple, chronological
- Easy to query and filter
- Obvious distinction from official seasons
- Future-proof and flexible

**Admin interface now supports:**
- 🏆 Official Season button (auto-fills `s##`)
- 🌟 Pre-Season button (auto-fills `ps##`)
- Automatic numbering for both types
- Smart defaults for each type
