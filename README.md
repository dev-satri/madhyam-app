<p align="center">
    <img src="https://img.shields.io/badge/Laravel-12-FF2D20?style=for-the-badge&logo=laravel&logoColor=white" alt="Laravel 12">
    <img src="https://img.shields.io/badge/Livewire-3-4E5AE8?style=for-the-badge&logo=livewire&logoColor=white" alt="Livewire 3">
    <img src="https://img.shields.io/badge/Tailwind_CSS-3.4-06B6D4?style=for-the-badge&logo=tailwindcss&logoColor=white" alt="Tailwind CSS">
    <img src="https://img.shields.io/badge/PHP-8.2-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8.2">
    <img src="https://img.shields.io/badge/Tests-55%20Passing-brightgreen?style=for-the-badge" alt="Tests">
    <img src="https://img.shields.io/badge/License-MIT-yellow?style=for-the-badge" alt="MIT License">
</p>

<h1 align="center">Madhyam</h1>

<p align="center">
    <strong>Agency Management System</strong> — A full-featured platform for managing clients, workflows, content planning, files, approvals, team, salary, expenses, and more.
</p>

<p align="center">
    Built with Laravel 12, Livewire 3 (Volt), Tailwind CSS, and Alpine.js
</p>

---

## Table of Contents

- [Features](#features)
- [Tech Stack](#tech-stack)
- [Architecture](#architecture)
- [Modules](#modules)
- [RBAC System](#rbac-system)
- [Client Portal](#client-portal)
- [Services Layer](#services-layer)
- [Getting Started](#getting-started)
- [Database Schema](#database-schema)
- [Seeding](#seeding)
- [Testing](#testing)
- [CI/CD Pipeline](#cicd-pipeline)
- [Project Structure](#project-structure)

---

## Features

| Module | Capabilities |
|--------|-------------|
| **Dashboard** | Role-based stats, charts (Chart.js), activity feed, quick actions |
| **Clients** | CRUD, package assignment, client portal accounts, usage tracking |
| **Packages** | Tiered plans with limits (content, workflows, files, approvals), upgrade/downgrade |
| **Content Planner** | Calendar view, content scheduling per client, status tracking |
| **Workflow** | Kanban-style drag-and-drop board with custom stages, file attachments |
| **Tasks & Shoots** | Task management with deadlines, shoot scheduling, auto-reminders, comments |
| **Approvals** | Multi-step approval flow with client/staff commenting, approve/reject |
| **Files & Media** | Folder hierarchy, drag-drop upload, file expiry with extend, grid/list view, drag-drop move, image/video/audio preview |
| **Reports & Finance** | Invoice management, payment tracking (paid/half/installment/discount), expense tracking |
| **Leaves** | Leave requests with approval workflow |
| **Expenses** | Expense logging with categories, date ranges, search |
| **Salary** | Auto-calculated salary (base + OT + bonus - deductions), downloadable payslips |
| **Overtime** | Overtime logging, CSV export, filterable views |
| **Complaints** | Staff/client complaint system with threaded replies |
| **Team** | Member management, departments, roles, profile avatars |
| **Settings** | Agency config, feature toggles, RBAC management, data backup/restore |
| **User Guide** | Built-in help documentation |
| **Notifications** | Real-time in-app notifications, rule-based auto-notifications, toast alerts |

---

## Tech Stack

| Layer | Technology |
|-------|-----------|
| **Backend** | Laravel 12, PHP 8.2+ |
| **Frontend** | Livewire 3 (Volt SFC), Tailwind CSS 3.4, Alpine.js |
| **Build** | Vite 7, PostCSS, Autoprefixer |
| **Database** | SQLite (default), MySQL/PostgreSQL (production) |
| **Icons** | FontAwesome 7 (bundled via npm) |
| **Charts** | Chart.js 4 + chartjs-plugin-datalabels |
| **Drag & Drop** | SortableJS |
| **Testing** | PHPUnit 11, Laravel Pint (code style) |
| **CI** | GitHub Actions |

---

## Architecture

```
┌─────────────────────────────────────────────────────────┐
│                     Browser (Livewire)                   │
│  Volt SFC Pages ─── Alpine.js ─── Tailwind CSS          │
└──────────────────────┬──────────────────────────────────┘
                       │ Livewire Update
┌──────────────────────▼──────────────────────────────────┐
│                   Laravel 12 Backend                     │
│                                                          │
│  ┌─────────────┐  ┌──────────────┐  ┌───────────────┐  │
│  │   Volt Pages │  │   Middleware  │  │   Services    │  │
│  │  (Livewire)  │  │  (RBAC/Auth)  │  │  (Business)   │  │
│  └──────┬──────┘  └──────┬───────┘  └───────┬───────┘  │
│         │                │                   │           │
│  ┌──────▼────────────────▼───────────────────▼───────┐  │
│  │              Eloquent Models (30+)                 │  │
│  └──────────────────────┬────────────────────────────┘  │
│                         │                                │
│  ┌──────────────────────▼────────────────────────────┐  │
│  │         SQLite / MySQL / PostgreSQL                │  │
│  │              36+ Tables                            │  │
│  └───────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────┘
```

**Key patterns:**
- **Volt Single-File Components** — Each page is a self-contained `.blade.php` file with PHP class + Blade template
- **Dual Auth Guards** — `web` guard for staff, `client` guard for client portal users
- **Runtime RBAC** — Feature gates + data-access permissions checked at service/middleware level
- **Service Layer** — Business logic extracted into dedicated service classes

---

## Modules

### Sidebar Navigation

**Staff Portal (16 modules):**

| Section | Module | Route |
|---------|--------|-------|
| **Main** | Dashboard | `/dashboard` |
| | Clients | `/clients` |
| | Packages | `/packages` |
| | Content Planner | `/content-planner` |
| **Production** | Workflow | `/workflow` |
| | Tasks & Shoots | `/tasks` |
| | Approvals | `/approvals` |
| **Management** | Files & Media | `/files` |
| | Reports & Finance | `/reports` |
| | Leaves | `/leaves` |
| | Expenses | `/expenses` |
| **Admin** | Team | `/team` |
| | Salary | `/salary` |
| | Overtime | `/overtime` |
| | Complaints | `/complaints` |
| | Settings | `/settings` |
| | User Guide | `/user-guide` |

All sidebar links are filtered through RBAC — users only see modules their role has access to.

---

## RBAC System

### 8 Built-in Roles

| Role | Access Level |
|------|-------------|
| `super-admin` | Full system access, cannot be deleted |
| `admin` | All modules, limited member management |
| `manager` | Team oversight, all content modules |
| `editor` | Content editing, workflow, approvals |
| `videographer` | Restricted to assigned tasks only |
| `designer` | Restricted to assigned tasks only |
| `copywriter` | Restricted to assigned tasks only |
| `social-media` | Restricted to assigned tasks only |

### 18 Feature Permissions

`dashboard`, `clients`, `packages`, `contentPlanner`, `workflow`, `tasks`, `approvals`, `files`, `reports`, `leaves`, `expenses`, `salary`, `overtime`, `team`, `settings`, `complaints`, `userGuide`, `notifications`

### 7 Data-Access Permissions

`seeAllTasks`, `seeAllWorkflow`, `seeAllApprovals`, `seeAllContent`, `seeTeamSalary`, `seeTeamExpenses`, `manageDepartments`

### Custom Roles

Create unlimited custom roles via Settings with fine-grained feature + data-access control.

---

## Client Portal

Clients get their own login (`/client/login`) with a limited sidebar:

| Module | Description |
|--------|-------------|
| Overview | Client-specific dashboard |
| Content Schedule | View scheduled content |
| Workflow | See their project pipeline |
| Approvals | Approve/reject content |
| Complaints | Submit and track complaints |
| Billing | View invoices and payments |
| Profile | Manage account details |

Client portal access is enforced via `EnsureClientPortalAccess` middleware with a 7-item route whitelist.

---

## Services Layer

| Service | Purpose |
|---------|---------|
| `RbacService` | Feature gates, data-access checks, role queries |
| `PackageService` | Usage tracking, limits, upgrades, per-module counters |
| `PaymentTracker` | Invoice status calculation (paid/half/installment/discount/overdue) |
| `SalaryCalculator` | Monthly salary computation (base + OT + bonus - leave deductions) |
| `NotificationService` | In-app notifications, task assignment alerts, auto-trim to 50 |
| `ActivityLogger` | Activity log entries, auto-trim to 100 entries |
| `DataBackupService` | Full JSON export/import of all 29+ tables |

---

## Getting Started

### Prerequisites

- PHP 8.2+
- Node.js 20+
- Composer
- npm

### Installation

```bash
# Clone the repository
git clone <repository-url>
cd madhyam-app

# Install dependencies
composer install
npm install

# Environment setup
cp .env.example .env
php artisan key:generate

# Database
php artisan migrate
php artisan db:seed

# Build frontend
npm run build

# Start development server
composer run dev
```

### Quick Setup

```bash
# One command to setup everything
composer run setup
```

This runs: `composer install` → `.env` copy → `key:generate` → `migrate` → `npm install` → `npm run build`

### Default Demo Accounts

After seeding, the following demo accounts are available:

#### Staff Accounts

| Email | Password | Role |
|-------|----------|------|
| super@madhyam.com | admin123 | Super Admin |
| rajesh@madhyam.com | pass123 | Manager |
| sita@madhyam.com | pass123 | Videographer |
| anil@madhyam.com | pass123 | Editor |
| priya@madhyam.com | pass123 | Designer |
| bikash@madhyam.com | pass123 | Social Media |
| karma@madhyam.com | pass123 | Copywriter |

#### Client Portal Accounts

| Email | Password | Client |
|-------|----------|--------|
| ram@himalayancoffee.com | client123 | Himalayan Coffee |
| maya@treknepal.com | client123 | Trek Nepal |
| devi@greenleaf.com | client123 | Green Leaf |

> **Note:** Client portal users log in via the "Client Portal" tab on the login page.

---

## Database Schema

**36 migration files** creating **30+ tables:**

| Category | Tables |
|----------|--------|
| **Core** | `users`, `departments`, `clients`, `client_accounts` |
| **Content** | `contents`, `workflows`, `workflow_stages`, `tasks`, `task_comments` |
| **Approvals** | `approvals`, `approval_comments` |
| **Files** | `folders`, `files`, `file_expiries` |
| **Finance** | `invoices`, `invoice_payments`, `expenses`, `salaries`, `overtime_logs` |
| **HR** | `leaves`, `working_hours` |
| **Complaints** | `complaints`, `complaint_replies` |
| **RBAC** | `feature_access`, `data_access`, `custom_roles` |
| **System** | `settings`, `notifications`, `notification_rules`, `activity_logs`, `packages`, `package_usage` |
| **Cache/Jobs** | `cache`, `jobs`, `job_batches`, `failed_jobs` |

---

## Seeding

**30 seeders** executed in 7 dependency-ordered phases:

| Phase | Seeders |
|-------|---------|
| **0** | Settings, Working Hours, Departments, Packages, Workflow Stages, Notification Rules |
| **1** | Demo Accounts (staff users) |
| **2** | Clients |
| **3** | Client Accounts |
| **4** | Content, Workflows, Tasks, Task Comments, Approvals, Approval Comments |
| **5** | Folders, Files |
| **6** | Invoices, Invoice Payments, Expenses, Salaries, Overtime Logs, Leaves |
| **7** | Feature Access, Data Access, Custom Roles, Complaints, Notifications, Package Usage, Activity Logs |

```bash
php artisan db:seed                    # Run all seeders
php artisan db:seed --class=FileSeeder # Run a specific seeder
```

---

## Testing

```bash
# Run all tests
php artisan test

# Run with verbose output
php artisan test --verbose

# Run specific test file
php artisan test --filter=AuthenticationTest
```

**55 tests, 128 assertions** covering:
- Authentication (login, logout, password reset)
- Profile management
- Client portal isolation
- Workflow drag-and-drop permissions
- Feature access gates

---

## CI/CD Pipeline

GitHub Actions workflow (`.github/workflows/ci.yml`):

**Job 1: Tests**
- PHP 8.2 + Node 20
- `composer install` → `npm ci` → `npm run build`
- `php artisan test`
- `vendor/bin/pint --test` (code style)

**Job 2: Build Verification**
- Production build (`npm run build` without dev dependencies)
- Verifies `public/build/` output exists

**Triggers:** Push to `main`/`master`, pull requests targeting `main`/`master`

---

## Project Structure

```
madhyam-app/
├── app/
│   ├── Console/              # Artisan commands (ShootReminder, DeadlineReminder, ContractExpiry)
│   ├── Http/
│   │   ├── Middleware/        # 5 custom middleware (RBAC, security, client portal)
│   │   └── Livewire/Actions/ # Logout action
│   ├── Livewire/Components/  # Reusable Livewire components
│   ├── Models/               # 30+ Eloquent models
│   ├── Providers/            # AppServiceProvider, VoltServiceProvider
│   ├── Services/             # 7 business logic services
│   └── Support/              # helpers.php (global utility functions)
├── database/
│   ├── migrations/           # 36 migration files
│   └── seeders/              # 30 seeder classes
├── resources/
│   ├── css/                  # app.css (Tailwind, skeleton, print styles)
│   ├── js/                   # app.js (Chart.js, SortableJS, FontAwesome)
│   └── views/
│       ├── components/       # Reusable Blade components + layout
│       └── livewire/pages/   # 20 Volt page directories
├── routes/
│   └── web.php               # 30+ route definitions (staff + client portal)
├── tests/                    # 55 feature tests
├── .github/workflows/        # CI pipeline
└── vite.config.js            # Vite configuration
```

---

## License

This project is open-sourced software licensed under the [MIT License](https://opensource.org/licenses/MIT).
