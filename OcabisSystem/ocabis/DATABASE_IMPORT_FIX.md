# Database Import Fix Guide

## Problem Description

When importing your `.sql` database file, you may encounter errors like:

```
ALTER TABLE `items`  
ADD CONSTRAINT `fk_items_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
ADD CONSTRAINT `items_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`);
```

### Common Causes:
1. **Constraint already exists** - The constraint was already created in a previous import
2. **Missing column** - The `category_id` column doesn't exist in the `items` table
3. **Referenced data missing** - The `items` table has `category_id` or `department_id` values that don't exist in the referenced tables
4. **Table order** - Tables are created in the wrong order (child tables before parent tables)

## Solutions

### Solution 1: Use the Safe Import Script (Recommended)

1. Access the safe import script via browser:
   ```
   http://localhost/ocabisFrontend/ocabis/safe_database_import.php
   ```

2. Login with your super admin credentials

3. Select your `.sql` file and click "Import Database"

4. The script will automatically:
   - Skip duplicate constraints
   - Handle missing columns
   - Add missing constraints safely
   - Show you a detailed log of what was done

### Solution 2: Use the SQL Fix Script

If you've already imported the database and got errors:

1. Open phpMyAdmin or your MySQL client

2. Select your database (usually `ocabis`)

3. Go to the SQL tab

4. Copy and paste the contents of `fix_constraints.sql`

5. Click "Go" to execute

This script will:
- Add the `category_id` column if missing
- Populate `category_id` from existing `category` names
- Safely add both foreign key constraints
- Verify the constraints were created

### Solution 3: Manual Fix in phpMyAdmin

If you prefer to fix it manually:

#### Step 1: Add category_id column (if missing)
```sql
ALTER TABLE `items` 
ADD COLUMN `category_id` INT NULL AFTER `department_id`;
```

#### Step 2: Populate category_id from category names
```sql
UPDATE items i
JOIN categories c ON c.name = i.category
SET i.category_id = c.id
WHERE i.category_id IS NULL AND i.category IS NOT NULL;
```

#### Step 3: Create index on category_id
```sql
CREATE INDEX idx_items_category_id ON items(category_id);
```

#### Step 4: Drop existing constraints (if they exist)
```sql
ALTER TABLE `items` DROP FOREIGN KEY `fk_items_category`;
ALTER TABLE `items` DROP FOREIGN KEY `items_ibfk_1`;
```

#### Step 5: Add constraints
```sql
ALTER TABLE `items` 
ADD CONSTRAINT `fk_items_category` 
FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) 
ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `items` 
ADD CONSTRAINT `items_ibfk_1` 
FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`);
```

### Solution 4: Modify SQL File Before Import

If you want to fix the SQL file before importing:

1. Open your `.sql` file in a text editor

2. Find the section that adds constraints (usually at the end)

3. Replace constraint addition statements with this pattern:

```sql
-- Check and add fk_items_category
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
    'SELECT 1;'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
```

## Prevention

To prevent this issue in future exports:

1. The `database_export.php` script already includes safe constraint handling
2. Always use the export feature from the admin panel rather than manual exports
3. The export script checks for existing constraints before adding them

## Verification

After applying any solution, verify the constraints exist:

```sql
SELECT 
    CONSTRAINT_NAME,
    TABLE_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME = 'items'
AND CONSTRAINT_NAME IN ('fk_items_category', 'items_ibfk_1');
```

You should see both constraints listed.

## Troubleshooting

### Error: "Cannot add foreign key constraint"
- **Cause**: Referenced table or column doesn't exist
- **Fix**: Ensure `categories` and `departments` tables exist and have `id` columns

### Error: "Duplicate key name"
- **Cause**: Constraint already exists
- **Fix**: Drop the existing constraint first, then add it again

### Error: "Cannot add or update a child row"
- **Cause**: Data in `items` table references non-existent categories or departments
- **Fix**: 
  ```sql
  -- Find orphaned records
  SELECT * FROM items 
  WHERE category_id NOT IN (SELECT id FROM categories) 
     OR department_id NOT IN (SELECT id FROM departments);
  
  -- Fix or delete orphaned records
  ```

## Support

If you continue to experience issues:
1. Check the import log from the safe import script
2. Verify all referenced tables exist
3. Ensure data integrity (no orphaned foreign keys)
4. Check MySQL error logs for detailed error messages

