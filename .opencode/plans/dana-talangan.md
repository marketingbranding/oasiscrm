# Dana Talangan Module Plan

## Overview
New module to track loan/advance payments (Dana Talangan) for property purchases. Tracks customers who received financing, their payment status, and finance confirmation.

## Database Migration

**Table: `dana_talangans`**

| Column | Type | Notes |
|---|---|---|
| id | bigint (PK) | auto-increment |
| tanggal | date | Loan date |
| nama_konsumen | string(255) | Customer name |
| kav | string(100) | Lot/unit number |
| project_name | string(255) | Project name |
| pinjam_nama | boolean | Borrow name (true/false) |
| pekerjaan | string(255) | Job/occupation |
| status_perkawinan | string(100) | Marital status |
| umur | integer | Age |
| nama_marketing | string(255) | Marketing person |
| penyelesaian | text | Completion notes |
| konfirmasi_keuangan | boolean | Finance confirmed |
| branch_id | foreign key | References branches.id |
| status | string(50) | 'aktif' or 'lunas' |
| created_by | foreign key | References users.id |
| timestamps | | created_at, updated_at |

## Model: `DanaTalangan`

- `$fillable`: all fields except id, timestamps
- `$casts`: tanggal => date, pinjam_nama => boolean, konfirmasi_keuangan => boolean, umur => integer
- Relationships: `branch()`, `creator()`

## Controller: `DanaTalanganController`

### Methods
1. `index()` - List with filters (branch, project, status)
2. `create()` - Show create form
3. `store()` - Validate and save
4. `edit()` - Show edit form
5. `update()` - Validate and update
6. `destroy()` - Delete record

### Filters
- `branch_id` - Filter by branch
- `project_name` - Filter by project
- `status` - Filter by status (aktif/lunas)

### Access Control
- Superadmin: sees all branches/projects
- Admin cabang: sees only their branch

## Views

### 1. `index.blade.php`
- Header: Dana Talangan (brick red #c0392b or gold #f1c40f)
- Filter form: Branch, Project, Status dropdowns
- Table: All columns with status badges
- Status badges:
  - aktif: blue (#9ab6c8)
  - lunas: green (#b3bd95)
- Actions: Edit, Delete
- Konfirmasi column: checkbox (readonly in index, editable in edit)

### 2. `create.blade.php`
- Header: Buat Dana Talangan
- Form fields:
  - Tanggal (date picker)
  - Nama Konsumen (text)
  - Kav (text)
  - Cabang (custom dropdown, filtered by role)
  - Proyek (custom dropdown, filtered by branch)
  - Pinjam Nama/Tidak (checkbox)
  - Pekerjaan (text)
  - Status Perkawinan (text)
  - Umur (number)
  - Nama Marketing (text)
  - Penyelesaian (textarea)
- Buttons: Simpan, Batal

### 3. `edit.blade.php`
- Header: Edit Dana Talangan
- Same form as create, plus:
  - Status dropdown (aktif/lunas)
  - Konfirmasi ke Keuangan checkbox
  - Penyelesaian textarea
- Buttons: Update, Batal, Hapus

## Routes

```php
Route::resource('dana-talangan', DanaTalanganController::class);
Route::bind('dana_talangan', fn($v) => DanaTalangan::findOrFail($v));
```

## Sidebar Menu Item

Add after Lead Harian:
```html
<a href="{{ route('dana-talangan.index') }}" class="block px-3 py-2 text-sm font-[Helvetica] font-bold text-black hover:bg-[#f1c40f] hover:text-black border border-black mb-1 rounded-none {{ request()->routeIs('dana-talangan.*') ? 'bg-[#f1c40f]' : 'bg-white' }}">
    <span class="text-[#f1c40f]">█</span> Dana Talangan
</a>
```

Color: Gold (#f1c40f) - represents finance/money

## Files to Create/Modify

| File | Action |
|---|---|
| `database/migrations/xxxx_create_dana_talangans_table.php` | CREATE |
| `app/Models/DanaTalangan.php` | CREATE |
| `app/Http/Controllers/Crm/DanaTalanganController.php` | CREATE |
| `resources/views/crm/dana-talangan/index.blade.php` | CREATE |
| `resources/views/crm/dana-talangan/create.blade.php` | CREATE |
| `resources/views/crm/dana-talangan/edit.blade.php` | CREATE |
| `routes/web.php` | ADD route + bind |
| `resources/views/layouts/crm.blade.php` | ADD sidebar item |

## Status Workflow

1. **Create** - User creates new Dana Talangan record (status: 'aktif')
2. **Update** - User updates penyelesaian notes as payments are made
3. **Confirm** - Finance checks konfirmasi_keuangan checkbox
4. **Complete** - User changes status to 'lunas' when fully paid (row turns green)
