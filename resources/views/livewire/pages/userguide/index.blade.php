<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth;
use App\Services\RbacService;

new #[Layout('components.layouts.app')] class extends Component
{
    public string $activeSection = 'dashboard';

    protected RbacService $rbac;

    public function boot(): void
    {
        $this->rbac = app(RbacService::class);
    }

    public function canAccess(string $feature): bool
    {
        $user = Auth::user() ?? Auth::guard('client')->user();
        if (!$user) return false;
        return $this->rbac->hasFeature($user->role, $feature);
    }

    public function getSections(): array
    {
        return [
            ['id' => 'dashboard', 'icon' => 'fa-tachometer-alt', 'title' => 'Dashboard', 'feature' => 'dashboard', 'access' => 'All staff',
             'steps' => [
                 'Login to see the dashboard overview with key metrics.',
                 'View stat cards for clients, projects, approvals, and revenue.',
                 'Check the Revenue Chart for monthly trends.',
                 'Review Task Distribution and Platform charts.',
                 'Use the Working Hours widget to log daily hours.',
                 'See the Activity Trace for recent actions across the system.',
                 'Respond to any backup reminder banners.',
             ],
             'tip' => 'The dashboard is your home base. Check it first each day to stay on top of work.',
            ],
            ['id' => 'clients', 'icon' => 'fa-building', 'title' => 'Clients', 'feature' => 'clients', 'access' => 'Manager+',
             'steps' => [
                 'Navigate to Clients from the sidebar.',
                 'Click "Add Client" to create a new client record.',
                 'Fill in name, email, phone, package, and status.',
                 'Use the search bar to filter clients by name or email.',
                 'Click a client row to open the detail modal.',
                 'Switch between Overview, Workflows, Content, and Invoices tabs.',
                 'Cycle client status (Active/Paused/Churned) from the table.',
             ],
             'tip' => 'Always keep client records updated. Accurate data helps with invoicing and workflow management.',
            ],
            ['id' => 'content-planner', 'icon' => 'fa-calendar-alt', 'title' => 'Content Planner', 'feature' => 'contentPlanner', 'access' => 'Manager+, Social Media',
             'steps' => [
                 'Open Content Planner from the sidebar.',
                 'Use the Month view to see all scheduled content.',
                 'Click a date or "Add Content" to create a new post.',
                 'Select client, platform, content type, and assign a team member.',
                 'Set publish date and add a script or description.',
                 'Use filters to view content by client, platform, or status.',
                 'Switch to List view for a tabular overview.',
             ],
             'tip' => 'Plan content at least a week ahead. The calendar view helps spot gaps in your publishing schedule.',
            ],
            ['id' => 'workflow', 'icon' => 'fa-columns', 'title' => 'Workflow', 'feature' => 'workflow', 'access' => 'Manager+, Videographer',
             'steps' => [
                 'Open Workflow to see the Kanban board.',
                 'Each column represents a stage (Idea, Shooting, Editing, Review).',
                 'Drag cards between columns to advance workflow.',
                 'Click a card to see details and add comments.',
                 'Use the stage manager (gear icon) to add/reorder stages.',
                 'Create new workflow items with the "Add Item" button.',
                 'Filter by client or assignee to focus on specific work.',
             ],
             'tip' => 'Keep cards moving through stages. Stuck items should be flagged in daily standups.',
            ],
            ['id' => 'tasks', 'icon' => 'fa-tasks', 'title' => 'Tasks', 'feature' => 'tasks', 'access' => 'All staff',
             'steps' => [
                 'View all tasks assigned to you or your team.',
                 'Click "Add Task" to create a new task.',
                 'Set title, description, assignee, priority, and due date.',
                 'Use tabs (All, Tasks, Shoots, Editing) to filter by type.',
                 'Click a task to open the detail modal with comments.',
                 'Cycle status: Todo → In Progress → Completed.',
                 'Use the priority filter to focus on urgent items.',
             ],
             'tip' => 'Update task status promptly. Real-time status helps managers plan workload.',
            ],
            ['id' => 'approvals', 'icon' => 'fa-check-double', 'title' => 'Approvals', 'feature' => 'approvals', 'access' => 'Manager+',
             'steps' => [
                 'Open Approvals to see pending items awaiting review.',
                 'Review content, design, or deliverable details.',
                 'Add feedback comments before approving or requesting revision.',
                 'Click Approve to move to the next stage.',
                 'Click Request Revision to send back with comments.',
                 'Use filters to view by client or status.',
                 'Bulk-approve multiple items when appropriate.',
             ],
             'tip' => 'Provide specific feedback on revisions. Vague comments cause rework cycles.',
            ],
            ['id' => 'files', 'icon' => 'fa-folder-open', 'title' => 'Files', 'feature' => 'files', 'access' => 'All staff',
             'steps' => [
                 'Open Files to manage your file library.',
                 'Create folders to organize files by client or type.',
                 'Click "Upload Files" to add new files with drag-drop.',
                 'Add tags to files for easy searching.',
                 'Preview files directly in the browser.',
                 'Check expiry dates and extend when needed.',
                 'Download files or move them between folders.',
             ],
             'tip' => 'Use descriptive file names and tags. This makes finding files much easier later.',
            ],
            ['id' => 'reports', 'icon' => 'fa-chart-bar', 'title' => 'Reports & Finance', 'feature' => 'reports', 'access' => 'Manager+',
             'steps' => [
                 'Open Reports to see financial overview and invoices.',
                 'Create invoices for client work with line items.',
                 'Record payments (full, half, installment, discount).',
                 'Track payment status and overdue invoices.',
                 'Export data as CSV for accounting.',
                 'View per-client revenue breakdowns.',
                 'Clients see their own billing in the portal.',
             ],
             'tip' => 'Invoice promptly after project completion. Delayed invoicing delays payment.',
            ],
            ['id' => 'leaves', 'icon' => 'fa-calendar-times', 'title' => 'Leaves', 'feature' => 'leaves', 'access' => 'All staff',
             'steps' => [
                 'Click "Apply Leave" to submit a leave request.',
                 'Select leave type (Casual, Sick, Annual, Personal).',
                 'Choose start and end dates.',
                 'Add a reason (optional).',
                 'Managers see pending approvals and can approve/reject.',
                 'Check your leave balance in the stats cards.',
                 'View leave history with status filters.',
             ],
             'tip' => 'Apply for leaves well in advance. Last-minute requests may be rejected during busy periods.',
            ],
            ['id' => 'expenses', 'icon' => 'fa-receipt', 'title' => 'Expenses', 'feature' => 'expenses', 'access' => 'Manager+',
             'steps' => [
                 'Click "Add Expense" to log a new expense.',
                 'Select category (Salary, Office, Equipment, Travel, etc.).',
                 'Category-specific fields appear (staff name, location, item, destination).',
                 'Enter amount, paid to, payment method, and client (optional).',
                 'Toggle status between Pending and Paid.',
                 'Use the Category Breakdown widget to see spending by category.',
                 'Export expenses as CSV for accounting.',
             ],
             'tip' => 'Categorize expenses accurately. This helps with budget tracking and tax reporting.',
            ],
            ['id' => 'team', 'icon' => 'fa-users', 'title' => 'Team', 'feature' => 'team', 'access' => 'Manager+',
             'steps' => [
                 'View all team members in the Members tab.',
                 'Click "Add Member" to invite new team members.',
                 'Set name, email, role, department, and status.',
                 'Password is required for new members, optional for edits.',
                 'Switch to Departments tab to manage departments.',
                 'Delete departments only when they have no members.',
                 'Super-admin cannot be edited or deleted by others.',
             ],
             'tip' => 'Assign appropriate roles. Over-permissioning creates security risks.',
            ],
            ['id' => 'salary', 'icon' => 'fa-money-bill-wave', 'title' => 'Salary', 'feature' => 'salary', 'access' => 'Manager+',
             'steps' => [
                 'Click "Ensure Records" to auto-generate salary records for all active staff.',
                 'View payroll stats: total, paid, pending, average, bonus.',
                 'Click base salary to edit and see live net preview.',
                 'Click bonus amount to edit with live preview.',
                 'Click OT Pay to see overtime log breakdown.',
                 'Click deduction to see leave deduction details.',
                 'Cycle payment status: Pending → Paid → Approved.',
             ],
             'tip' => 'Review salary records before marking as Paid. Use the breakdown modal to verify calculations.',
            ],
            ['id' => 'overtime', 'icon' => 'fa-clock', 'title' => 'Overtime', 'feature' => 'overtime', 'access' => 'All staff',
             'steps' => [
                 'Click "Log Overtime" to record overtime hours.',
                 'Select date, hours (0.5 step), and rate per hour.',
                 'Add a description of the overtime work.',
                 'Managers approve/revoke overtime with the toggle.',
                 'View staff summary to see who worked overtime.',
                 'Filter by month, status, or staff member.',
                 'Export overtime logs as CSV.',
             ],
             'tip' => 'Log overtime promptly. Late logs may miss the salary calculation cycle.',
            ],
            ['id' => 'settings', 'icon' => 'fa-cog', 'title' => 'Settings', 'feature' => 'settings', 'access' => 'Super Admin only',
             'steps' => [
                 'General: Set agency name, email, phone, currency, brand color.',
                 'Working Hours: Toggle active days and set start/end times.',
                 'Feature Access: Toggle which features each role can access.',
                 'Data Access: Control data visibility permissions per role.',
                 'Backup: Export all data as JSON for safekeeping.',
                 'Use the Role Manager to create custom roles.',
                 'Brand color changes update the sidebar accent.',
             ],
             'tip' => 'Only super-admins should modify settings. Test changes in a staging environment first.',
            ],
            ['id' => 'client-portal', 'icon' => 'fa-globe', 'title' => 'Client Portal', 'feature' => 'clientPortal', 'access' => 'Clients only',
             'steps' => [
                 'Clients log in with their dedicated credentials.',
                 'Dashboard shows their projects, approvals, and working hours.',
                 'Content Planner shows their scheduled content.',
                 'Workflow displays their project progress.',
                 'Approvals lets them approve or request revisions.',
                 'Complaints lets them submit and track support requests.',
                 'Profile lets them update their contact information.',
             ],
             'tip' => 'Encourage clients to use the portal. It reduces email back-and-forth significantly.',
            ],
        ];
    }

    public function render(): mixed
    {
        return <<<'blade'
        <div class="space-y-6">
            <div>
                <h1 class="text-2xl font-extrabold text-gray-900">User Guide</h1>
                <p class="text-sm text-gray-500 mt-1">Learn how to use each module of the system</p>
            </div>

            <div class="flex flex-col lg:flex-row gap-6">
                {{-- TOC Sidebar --}}
                <div class="lg:w-56 flex-shrink-0">
                    <div class="bg-white rounded-2xl border border-gray-100 p-4 sticky top-24">
                        <div class="flex items-center gap-3 mb-4 pb-3 border-b">
                            @php $guideUser = Auth::user() ?? Auth::guard('client')->user(); @endphp
                            <div class="w-10 h-10 rounded-full bg-[rgba(var(--brand-rgb),0.1)] flex items-center justify-center font-bold text-sm text-[var(--brand)]">{{ strtoupper(substr($guideUser->name ?? 'U', 0, 2)) }}</div>
                            <div>
                                <div class="text-sm font-semibold">{{ $guideUser->name ?? '' }}</div>
                                <div class="text-xs text-gray-400 capitalize">{{ str_replace('-', ' ', $guideUser->role ?? '') }}</div>
                            </div>
                        </div>
                        <nav class="space-y-0.5">
                            @foreach($this->getSections() as $s)
                                <a href="#{{ $s['id'] }}" wire:click.prevent="$set('activeSection', '{{ $s['id'] }}'); document.getElementById('{{ $s['id'] }}')?.scrollIntoView({behavior:'smooth'})"
                                   class="flex items-center gap-2 px-3 py-2 rounded-lg text-xs font-medium transition-colors {{ $activeSection === $s['id'] ? 'bg-[rgba(var(--brand-rgb),0.08)] text-[var(--brand)]' : 'text-gray-600 hover:bg-gray-50' }}">
                                    <i class="fas {{ $s['icon'] }} w-4"></i>
                                    {{ $s['title'] }}
                                    @if($this->canAccess($s['feature']))
                                        <span class="ml-auto w-2 h-2 rounded-full bg-green-400"></span>
                                    @else
                                        <span class="ml-auto w-2 h-2 rounded-full bg-red-300"></span>
                                    @endif
                                </a>
                            @endforeach
                        </nav>
                    </div>
                </div>

                {{-- Sections --}}
                <div class="flex-1 min-w-0 space-y-6">
                    @foreach($this->getSections() as $s)
                        <div id="{{ $s['id'] }}" class="bg-white rounded-2xl border border-gray-100 p-6 scroll-mt-24">
                            <div class="flex items-center gap-3 mb-4">
                                <div class="w-10 h-10 rounded-xl bg-[rgba(var(--brand-rgb),0.08)] flex items-center justify-center text-[var(--brand)]">
                                    <i class="fas {{ $s['icon'] }}"></i>
                                </div>
                                <div class="flex-1">
                                    <h2 class="font-bold text-lg">{{ $s['title'] }}</h2>
                                    <div class="text-xs text-gray-500">Who can access: {{ $s['access'] }}</div>
                                </div>
                                @if($this->canAccess($s['feature']))
                                    <span class="badge badge-approved"><i class="fas fa-check text-[10px]"></i> You have access</span>
                                @else
                                    <span class="badge badge-rejected"><i class="fas fa-lock text-[10px]"></i> No access</span>
                                @endif
                            </div>

                            <ol class="space-y-2 mb-4">
                                @foreach($s['steps'] as $i => $step)
                                    <li class="flex gap-3 text-sm">
                                        <span class="w-5 h-5 rounded-full bg-gray-100 flex items-center justify-center text-xs font-bold text-gray-500 flex-shrink-0 mt-0.5">{{ $i + 1 }}</span>
                                        <span class="text-gray-700">{{ $step }}</span>
                                    </li>
                                @endforeach
                            </ol>

                            <div class="bg-green-50 border border-green-100 rounded-xl p-3 text-sm text-green-700">
                                <i class="fas fa-lightbulb mr-1"></i> <strong>Tip:</strong> {{ $s['tip'] }}
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
        blade;
    }
};
