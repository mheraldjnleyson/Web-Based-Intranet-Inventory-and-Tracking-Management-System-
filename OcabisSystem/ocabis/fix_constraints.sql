-- ================================================
-- Fix Foreign Key Constraints for items table
-- Run this script AFTER importing your database
-- ================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- ================================================
-- Step 1: Add category_id column if it doesn't exist
-- ================================================
SET @col_exists = (
    SELECT COUNT(*) 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'items' 
    AND COLUMN_NAME = 'category_id'
);

SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE items ADD COLUMN category_id INT NULL AFTER department_id;',
    'SELECT "category_id column already exists" AS message;'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ================================================
-- Step 2: Populate category_id from category name
-- ================================================
UPDATE items i
JOIN categories c ON c.name = i.category
SET i.category_id = c.id
WHERE i.category_id IS NULL AND i.category IS NOT NULL;

-- ================================================
-- Step 3: Create index on category_id if it doesn't exist
-- ================================================
SET @idx_exists = (
    SELECT COUNT(*) 
    FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'items' 
    AND INDEX_NAME = 'idx_items_category_id'
);

SET @sql = IF(@idx_exists = 0, 
    'CREATE INDEX idx_items_category_id ON items(category_id);',
    'SELECT "Index idx_items_category_id already exists" AS message;'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ================================================
-- Step 4: Drop existing fk_items_category constraint if it exists (to recreate it)
-- ================================================
SET @constraint_exists = (
    SELECT COUNT(*) 
    FROM information_schema.TABLE_CONSTRAINTS 
    WHERE CONSTRAINT_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'items' 
    AND CONSTRAINT_NAME = 'fk_items_category'
);

SET @sql = IF(@constraint_exists > 0, 
    'ALTER TABLE items DROP FOREIGN KEY fk_items_category;',
    'SELECT "Constraint fk_items_category does not exist" AS message;'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ================================================
-- Step 5: Add fk_items_category constraint
-- ================================================
SET @constraint_exists = (
    SELECT COUNT(*) 
    FROM information_schema.TABLE_CONSTRAINTS 
    WHERE CONSTRAINT_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'items' 
    AND CONSTRAINT_NAME = 'fk_items_category'
);

SET @sql = IF(@constraint_exists = 0, 
    'ALTER TABLE items 
     ADD CONSTRAINT fk_items_category 
     FOREIGN KEY (category_id) REFERENCES categories(id) 
     ON DELETE SET NULL ON UPDATE CASCADE;',
    'SELECT "Constraint fk_items_category already exists" AS message;'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ================================================
-- Step 6: Drop existing items_ibfk_1 constraint if it exists (to recreate it)
-- ================================================
SET @constraint_exists = (
    SELECT COUNT(*) 
    FROM information_schema.TABLE_CONSTRAINTS 
    WHERE CONSTRAINT_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'items' 
    AND CONSTRAINT_NAME = 'items_ibfk_1'
);

SET @sql = IF(@constraint_exists > 0, 
    'ALTER TABLE items DROP FOREIGN KEY items_ibfk_1;',
    'SELECT "Constraint items_ibfk_1 does not exist" AS message;'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ================================================
-- Step 7: Add items_ibfk_1 constraint
-- ================================================
SET @constraint_exists = (
    SELECT COUNT(*) 
    FROM information_schema.TABLE_CONSTRAINTS 
    WHERE CONSTRAINT_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'items' 
    AND CONSTRAINT_NAME = 'items_ibfk_1'
);

SET @sql = IF(@constraint_exists = 0, 
    'ALTER TABLE items 
     ADD CONSTRAINT items_ibfk_1 
     FOREIGN KEY (department_id) REFERENCES departments(id);',
    'SELECT "Constraint items_ibfk_1 already exists" AS message;'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ================================================
-- Step 8: Verify constraints were added
-- ================================================
SELECT 
    CONSTRAINT_NAME,
    TABLE_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME = 'items'
AND CONSTRAINT_NAME IN ('fk_items_category', 'items_ibfk_1')
ORDER BY CONSTRAINT_NAME;

SET FOREIGN_KEY_CHECKS = 1;

-- ================================================
-- Script completed successfully!
-- ================================================

