-- Кошки
CREATE TABLE IF NOT EXISTS cats (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    name      TEXT NOT NULL,
    sex       TEXT NOT NULL CHECK (sex IN ('M', 'F')),
    age       INTEGER NOT NULL CHECK (age >= 0),
    breed     TEXT,
    notes     TEXT,
    litter_id INTEGER REFERENCES litters (id)
);

CREATE INDEX IF NOT EXISTS idx_cats_sex    ON cats (sex);
CREATE INDEX IF NOT EXISTS idx_cats_age    ON cats (age);
CREATE INDEX IF NOT EXISTS idx_cats_litter ON cats (litter_id);

-- Помёты: у каждого одна мать
CREATE TABLE IF NOT EXISTS litters (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    name      TEXT,
    mother_id INTEGER NOT NULL REFERENCES cats (id)
);

CREATE INDEX IF NOT EXISTS idx_litters_mother ON litters (mother_id);

-- Отцы: у одного помёта их может быть несколько
CREATE TABLE IF NOT EXISTS litter_sires (
    litter_id INTEGER NOT NULL REFERENCES litters (id),
    sire_id   INTEGER NOT NULL REFERENCES cats (id),
    PRIMARY KEY (litter_id, sire_id)
);

CREATE INDEX IF NOT EXISTS idx_sires ON litter_sires (sire_id);
