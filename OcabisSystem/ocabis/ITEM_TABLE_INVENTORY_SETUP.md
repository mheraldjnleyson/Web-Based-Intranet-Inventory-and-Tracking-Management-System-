# Item Table Inventory Tracking - Setup Guide

## Overview
Ito ay sistema para sa tracking ng items sa item tables. Isang QR code lang per item table, at pag na-scan, lalabas lahat ng items sa table na yun. Pwede manually mag-edit ng quantity at status.

## Features
- Scan o manually enter item table QR code
- Display lahat ng items sa table na yun
- Manually edit quantity at status (Working, Under Maintenance, Broken, Lost)
- Auto-log ng changes sa inventory_logs table
- Highlight rows kapag quantity decreased o status changed to Broken/Lost

## Database Setup

### Step 1: Run the SQL Migration
Execute ang SQL file para mag-add ng QR code field:

```sql
-- Run this file: add_item_table_qr_field.sql
```

O manually run:
```sql
ALTER TABLE `item_tables` 
ADD COLUMN IF NOT EXISTS `qr_code` varchar(255) DEFAULT NULL AFTER `table_image_path`;

ALTER TABLE `item_tables`
ADD INDEX IF NOT EXISTS `idx_qr_code` (`qr_code`);
```

### Step 2: Generate QR Code for Item Tables
Para sa bawat item table, kailangan mag-generate ng QR code. Pwede mo i-update manually:

```sql
UPDATE item_tables 
SET qr_code = 'TABLE-001' 
WHERE id = 1;
```

O kaya gamitin ang existing QR generation system para sa item tables.

## Usage

### 1. Access the Page
Navigate to: `item_table_inventory.php`

### 2. Scan or Enter Item Table QR Code
- Gamitin ang QR scanner para i-scan ang item table QR code, O
- Manually enter ang item table ID o QR code sa input field

### 3. View Items
- Lalabas lahat ng items sa table na yun sa isang table format
- Makikita mo: Item Code, Item Name, Quantity, Status

### 4. Edit Inventory
- Pwede mo i-edit directly ang **quantity** at **status** sa table
- Rows na may decreased quantity o changed status (Broken/Lost) ay mag-highlight

### 5. Save Changes
- Click "Save Inventory" button
- Changes ay:
  - I-update sa `items` table
  - I-log sa `inventory_logs` table (sino, kailan, ano ang nagbago)

## File Structure

- `item_table_inventory.php` - Main page na may QR scanner at editable table
- `item_table_inventory_api.php` - API endpoints para sa fetching at saving
- `Css/item_table_inventory.css` - Styling para sa page
- `add_item_table_qr_field.sql` - Database migration file

## API Endpoints

### GET `item_table_inventory_api.php?action=get_item_table&qr_code=XXX`
Kunin ang item table info by QR code.

### GET `item_table_inventory_api.php?action=get_item_table&table_id=XXX`
Kunin ang item table info by table ID.

### GET `item_table_inventory_api.php?action=get_items&item_table_id=XXX`
Kunin lahat ng items sa isang item table.

### POST `item_table_inventory_api.php` (JSON)
```json
{
  "action": "save_inventory",
  "item_table_id": 1,
  "updates": [
    {
      "item_id": 123,
      "quantity": 8,
      "status": "Working",
      "previous_quantity": 10,
      "previous_status": "Working"
    }
  ]
}
```

## Notes

- Ang sistema ay naka-integrate sa existing `items` table
- May `item_table_id` field ang `items` table para i-link sa `item_tables`
- Ang `quantity` at `status` fields ay nasa `items` table na
- Lahat ng changes ay auto-logged sa `inventory_logs` table
- Pwede mo i-edit ang quantity at status manually pagka-scan ng item table QR

## Example Workflow

1. **Generate QR code para sa item table**
   ```sql
   UPDATE item_tables SET qr_code = 'TABLE-001' WHERE id = 1;
   ```

2. **Print o i-display ang QR code** sa item table

3. **Scan ang QR code** gamit ang `item_table_inventory.php`

4. **Bilangin manually** ang items at i-update ang quantity

5. **I-update ang status** kung may Broken o Lost items

6. **Click "Save Inventory"** para i-save ang changes

7. **Check ang inventory_logs** para makita ang history ng changes

