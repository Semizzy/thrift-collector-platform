import { useEffect, useState, type FormEvent } from 'react';
import {
  Activity, ArrowDownLeft, ArrowRight, Bell, CalendarClock, CheckCircle2,
  ChevronDown, CircleHelp, ClipboardCheck, CreditCard, FileText, Home,
  LayoutDashboard, LogOut, Menu, Moon, Search, Settings, ShieldCheck,
  Sun, Users, Wallet, X, type LucideIcon,
} from 'lucide-react';

type Page = 'user' | 'admin';
type NavItem = { label: string; icon: LucideIcon };

const userNav: NavItem[] = [
  { label: 'Overview', icon: Home },
  { label: 'My groups', icon: Users },
  { label: 'Contributions', icon: Wallet },
  { label: 'Statements', icon: FileText },
  { label: 'Support & disputes', icon: CircleHelp },
];
const adminNav: NavItem[] = [
  { label: 'Overview', icon: LayoutDashboard },
  { label: 'Members & businesses', icon: Users },
  { label: 'Collections', icon: Wallet },
  { label: 'Reconciliation', icon: ClipboardCheck },
  { label: 'Approvals & disputes', icon: ShieldCheck },
  { label: 'Reports & audit', icon: Activity },
  { label: 'Settings', icon: Settings },
];

function getPage(): Page {
  return window.location.pathname.startsWith('/admin') ? 'admin' : 'user';
}

export default function App() {
  const [page, setPage] = useState<Page>(getPage);
  const [dark, setDark] = useState(false);
  const [menuOpen, setMenuOpen] = useState(false);
  const [active, setActive] = useState('Overview');
  const [searchOpen, setSearchOpen] = useState(false);

  useEffect(() => {
    document.documentElement.dataset.theme = dark ? 'dark' : 'light';
  }, [dark]);

  useEffect(() => {
    const handlePop = () => setPage(getPage());
    window.addEventListener('popstate', handlePop);
    return () => window.removeEventListener('popstate', handlePop);
  }, []);

  function navigate(next: Page) {
    const path = next === 'admin' ? '/admin' : '/dashboard';
    window.history.pushState({}, '', path);
    setPage(next);
    setActive('Overview');
    setMenuOpen(false);
  }

  const items = page === 'admin' ? adminNav : userNav;

  return (
    <div className="app-frame">
      {menuOpen && <button className="scrim" aria-label="Close navigation" onClick={() => setMenuOpen(false)} />}
      <aside className={`sidebar ${menuOpen ? 'sidebar-open' : ''}`} aria-label="Main navigation">
        <div className="brand">
          <div className="brand-mark"><Wallet size={21} strokeWidth={2.4} /></div>
          <div><strong>Thrift<span>Collect</span></strong><small>TRUSTED GROUP SAVINGS</small></div>
          <button className="icon-button mobile-close" aria-label="Close menu" onClick={() => setMenuOpen(false)}><X size={20} /></button>
        </div>
        <div className="workspace-label">{page === 'admin' ? 'PLATFORM ADMIN' : 'YOUR WORKSPACE'}</div>
        <nav className="nav-list">
          {items.map(({ label, icon: Icon }) => (
            <button key={label} className={`nav-link ${active === label ? 'nav-active' : ''}`} onClick={() => { setActive(label); setMenuOpen(false); }}>
              <Icon size={19} /><span>{label}</span>{label === 'Approvals & disputes' && page === 'admin' && <span className="nav-dot" />}
            </button>
          ))}
        </nav>
        <div className="sidebar-bottom">
          <div className="help-card"><div className="help-icon"><CircleHelp size={18} /></div><strong>Need a hand?</strong><p>Find guidance for using your workspace.</p><button onClick={() => setActive(page === 'admin' ? 'Settings' : 'Support & disputes')}>Open help <ArrowRight size={14} /></button></div>
          <div className="sidebar-profile"><div className="avatar">TC</div><div className="profile-copy"><strong>{page === 'admin' ? 'Platform operator' : 'Your account'}</strong><small>{page === 'admin' ? 'Protected read-only workspace' : 'Member workspace'}</small></div><button className="icon-button" aria-label="Account options"><ChevronDown size={17} /></button></div>
        </div>
      </aside>

      <main className="main-area">
        <header className="topbar">
          <div className="topbar-left">
            <button className="icon-button menu-trigger" aria-label="Open navigation" onClick={() => setMenuOpen(true)}><Menu size={21} /></button>
            <div className="breadcrumb"><span>Workspace</span><span className="crumb-slash">/</span><strong>{page === 'admin' ? 'Platform admin' : 'My dashboard'}</strong></div>
          </div>
          <div className="topbar-actions">
            <button className="switcher" onClick={() => navigate(page === 'admin' ? 'user' : 'admin')}><span className="switcher-dot" />{page === 'admin' ? 'User view' : 'Admin view'}<ArrowRight size={14} /></button>
            {searchOpen && <input autoFocus className="top-search" aria-label="Search this dashboard" placeholder="Search workspace…" onKeyDown={e => e.key === 'Escape' && setSearchOpen(false)} />}
            <button className="icon-button desktop-only" aria-label="Search" onClick={() => setSearchOpen(v => !v)}><Search size={19} /></button>
            <button className="icon-button" aria-label="Notifications"><Bell size={19} /><span className="notification-dot" /></button>
            <button className="icon-button" aria-label={dark ? 'Switch to light mode' : 'Switch to dark mode'} onClick={() => setDark(v => !v)}>{dark ? <Sun size={19} /> : <Moon size={19} />}</button>
          </div>
        </header>

        <div className="page-content">
          {page === 'admin' ? <AdminDashboard active={active} /> : <UserDashboard active={active} />}
          <footer className="footer"><span>© {new Date().getFullYear()} Thrift Collector Platform</span><span>Built for clarity, trust and accountability</span></footer>
        </div>
        <nav className="mobile-tabs" aria-label="Quick navigation">
          {(page === 'admin' ? adminNav.slice(0, 4) : userNav.slice(0, 4)).map(({ label, icon: Icon }) => (
            <button key={label} className={active === label ? 'tab-active' : ''} onClick={() => setActive(label)}><Icon size={19} /><span>{label === 'Overview' ? 'Home' : label === 'Contributions' ? 'Payments' : label === 'Statements' ? 'Reports' : label === 'Collections' ? 'Collect' : label === 'Reconciliation' ? 'Reconcile' : label === 'My groups' ? 'Groups' : label === 'Members & businesses' ? 'Members' : label}</span></button>
          ))}
        </nav>
      </main>
    </div>
  );
}

function SectionHeading({ eyebrow, title, description, action }: { eyebrow: string; title: string; description: string; action?: string }) {
  return <div className="section-heading"><div><div className="eyebrow">{eyebrow}</div><h1>{title}</h1><p>{description}</p></div>{action && <button className="button button-primary" onClick={() => window.alert('This action will be connected when the corresponding workflow is implemented.')}>{action}<ArrowRight size={16} /></button>}</div>;
}

function EmptyPanel({ icon: Icon, title, body }: { icon: LucideIcon; title: string; body: string }) {
  return <div className="empty-panel"><div className="empty-icon"><Icon size={23} /></div><strong>{title}</strong><p>{body}</p></div>;
}

type DashboardProfile = { id: number; name: string; email: string; email_verified_at: string | null };
type DashboardSummary = { user: DashboardProfile; active_workspaces_count: number; workspaces: Array<{ id: number; name: string; country_code: string; status: string; role: string }>; active_groups_count: number; groups: Array<{ id: number; business_id: number; name: string; description: string | null; currency_code: string; status: string; role: string; joined_at: string | null }>; confirmed_contribution_totals: Array<{ currency_code: string; total_minor: number; records_count: number }>; recent_contributions: Array<{ id: number; thrift_group_id: number; user_id: number; amount_minor: number; currency_code: string; status: string; collection_method: string; reference: string | null; created_at: string; confirmed_at: string | null; group: { id: number; name: string; currency_code: string } | null }>; upcoming_obligations_count: number; upcoming_obligations: Array<{ id: number; status: string; expected_amount_minor: number; currency_code: string; due_on: string | null; group: { id: number; name: string } | null }>; financial_data_status: string; message: string };

function UserDashboard({ active }: { active: string }) {
  const [token, setToken] = useState(() => sessionStorage.getItem('tcp_access_token') ?? '');
  const [profile, setProfile] = useState<DashboardProfile | null>(null);
  const [dashboardData, setDashboardData] = useState<DashboardSummary | null>(null);
  const [authLoading, setAuthLoading] = useState(Boolean(token));
  const [authError, setAuthError] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');

  useEffect(() => {
    if (!token) { setAuthLoading(false); setProfile(null); setDashboardData(null); return; }
    let cancelled = false;
    setAuthLoading(true);
    fetch('/api/v1/dashboard/summary', { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } })
      .then(async response => {
        if (!response.ok) throw new Error(response.status === 401 ? 'Your session expired. Please sign in again.' : 'Could not load your authenticated account data.');
        return response.json() as Promise<{ data: DashboardSummary }>;
      })
      .then(result => {
        if (!cancelled) { setProfile(result.data.user); setDashboardData(result.data); setAuthError(''); }
      })
      .catch(error => {
        if (!cancelled) { sessionStorage.removeItem('tcp_access_token'); setToken(''); setAuthError(error instanceof Error ? error.message : 'Please sign in to continue.'); }
      })
      .finally(() => { if (!cancelled) setAuthLoading(false); });
    return () => { cancelled = true; };
  }, [token]);

  async function signIn(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setAuthError(''); setAuthLoading(true);
    try {
      const response = await fetch('/api/v1/auth/login', {
        method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ email, password }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message ?? result.errors?.email?.[0] ?? 'Sign in failed. Check your details and try again.');
      sessionStorage.setItem('tcp_access_token', result.access_token);
      setToken(result.access_token); setPassword('');
    } catch (error) {
      setAuthError(error instanceof Error ? error.message : 'Sign in failed.'); setAuthLoading(false);
    }
  }

  async function signOut() {
    if (token) {
      try { await fetch('/api/v1/auth/logout', { method: 'POST', headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } }); } catch { /* Clear local session even if the network is unavailable. */ }
    }
    sessionStorage.removeItem('tcp_access_token'); setToken(''); setProfile(null); setDashboardData(null);
  }

  if (!token) return <section className="auth-gate"><div className="auth-card">
    <div className="brand-mark auth-brand"><Wallet size={22} /></div><div className="eyebrow">MEMBER ACCESS</div><h1>Welcome back.</h1>
    <p>Sign in to load your account details from the authenticated platform API.</p>
    <form className="auth-form" onSubmit={signIn}>
      <label htmlFor="member-email">Email address</label><input id="member-email" type="email" autoComplete="email" required value={email} onChange={event => setEmail(event.target.value)} />
      <label htmlFor="member-password">Password</label><input id="member-password" type="password" autoComplete="current-password" required value={password} onChange={event => setPassword(event.target.value)} />
      {authError && <p className="auth-error" role="alert">{authError}</p>}
      <button className="button button-primary auth-submit" type="submit" disabled={authLoading}>{authLoading ? 'Signing in…' : 'Sign in securely'} <ArrowRight size={16} /></button>
    </form><p className="auth-footnote">Registration and email verification use the platform's existing API endpoints.</p>
  </div></section>;
  if (authLoading && !profile) return <section className="auth-gate"><div className="auth-card"><strong>Loading your account…</strong><p>Checking your authenticated session.</p></div></section>;
  if (authError && !profile) return <section className="auth-gate"><div className="auth-card"><h1>Session needs attention</h1><p>{authError}</p><button className="button button-primary" onClick={signOut}>Return to sign in</button></div></section>;

  if (active === 'My groups' && dashboardData) return <><SectionHeading eyebrow="MEMBER WORKSPACE" title="My groups" description="Thrift groups where you have active membership." />{dashboardData.groups.length ? <div className="content-grid">{dashboardData.groups.map(group => <section className="panel" key={group.id}><div className="panel-heading"><div><h2>{group.name}</h2><p>{group.description || 'No group description provided.'}</p></div><span className="status-pill status-neutral">{group.status}</span></div><div className="stat-foot muted">{group.currency_code} · {group.role} · Group #{group.id}</div></section>)}</div> : <EmptyPanel icon={Users} title="No active thrift groups yet" body="When you join a thrift group, it will appear here." />}</>;
  if (active === 'Contributions' && dashboardData) return <><SectionHeading eyebrow="MEMBER WORKSPACE" title="Contributions" description="Your contribution records from groups you belong to." />{dashboardData.recent_contributions.length ? <section className="panel"><div className="panel-heading"><div><h2>Recent contribution records</h2><p>Record confirmation is not proof of bank deposit or provider settlement.</p></div></div><div className="contribution-list">{dashboardData.recent_contributions.map(record => <div className="check-row" key={record.id}><span className="check-number">{record.status === 'confirmed' ? <CheckCircle2 size={17} /> : <CalendarClock size={17} />}</span><div><strong>{record.group?.name ?? `Group #${record.thrift_group_id}`}</strong><p>{new Date(record.created_at).toLocaleDateString()} · {record.collection_method.replace('_', ' ')}</p></div><div className="contribution-amount"><strong>{new Intl.NumberFormat('en-NG', { style: 'currency', currency: record.currency_code }).format(record.amount_minor / 100)}</strong><small>{record.status}</small></div></div>)}</div></section> : <EmptyPanel icon={Wallet} title="No contribution records yet" body="Records will appear here when a permitted organizer or collector records a contribution for you." />}</>;

  if (active !== 'Overview') {
    const details: Record<string, { title: string; desc: string; icon: LucideIcon }> = {
      'My groups': { title: 'Your groups will appear here', desc: 'Once you join or are invited to a group, its membership, contribution schedule and rules will be shown here.', icon: Users },
      Contributions: { title: 'No contribution records loaded', desc: 'Confirmed and pending records will appear here when connected to your authenticated account data.', icon: Wallet },
      Statements: { title: 'Statements will appear here', desc: 'Your available statements and receipts will be listed here after the statement API is connected.', icon: FileText },
      'Support & disputes': { title: 'Support centre', desc: 'Support requests and dispute tracking will be available here when their workflows are implemented.', icon: CircleHelp },
    };
    const d = details[active] ?? details['My groups'];
    return <><SectionHeading eyebrow="MEMBER WORKSPACE" title={active} description="A clear view of your thrift activity and account." /><EmptyPanel icon={d.icon} title={d.title} body={d.desc} /></>;
  }

  return <>
    <SectionHeading eyebrow="MEMBER WORKSPACE" title={profile ? `Welcome back, ${profile.name.split(' ')[0]}.` : 'Your savings, at a glance.'} description={profile ? `Signed in as ${profile.email}. Your dashboard uses authenticated account data.` : 'Welcome to your personal thrift workspace. Your confirmed activity will appear here.'} />
    <div className="notice"><div className="notice-symbol"><Activity size={18} /></div><div className="notice-copy"><strong>Account data connected securely</strong><p>Your profile, active thrift groups and contribution records are loaded from the authenticated API. Financial totals remain hidden until verified contribution records exist.</p></div><button className="text-button signout-button" onClick={signOut}><LogOut size={15} /> Sign out</button></div>
    <section className="stats-grid" aria-label="Account summary">
      <article className="stat-card stat-featured"><div className="stat-top"><span>Total confirmed contributions</span><span className="stat-icon"><Wallet size={19} /></span></div><div className="stat-value">{dashboardData?.confirmed_contribution_totals.length ? dashboardData.confirmed_contribution_totals.map(total => `${total.currency_code} ${new Intl.NumberFormat('en-NG', { maximumFractionDigits: 2 }).format(total.total_minor / 100)}`).join(' · ') : '—'}</div><div className="stat-foot"><span className="status-pill status-neutral"><Activity size={13} /> Confirmed records only</span></div></article>
      <article className="stat-card"><div className="stat-top"><span>Active thrift groups</span><span className="stat-icon green"><Users size={19} /></span></div><div className="stat-value">{dashboardData?.active_groups_count ?? "—"}</div><div className="stat-foot muted">Groups where you have active membership</div></article>
      <article className="stat-card"><div className="stat-top"><span>Upcoming contributions</span><span className="stat-icon amber"><CalendarClock size={19} /></span></div><div className="stat-value">{dashboardData?.upcoming_obligations_count ?? '—'}</div><div className="stat-foot muted">Scheduled member obligations only</div></article>
      <article className="stat-card"><div className="stat-top"><span>Confirmed records</span><span className="stat-icon green"><CheckCircle2 size={19} /></span></div><div className="stat-value">{dashboardData?.confirmed_contribution_totals.reduce((sum, total) => sum + total.records_count, 0) ?? '—'}</div><div className="stat-foot muted">Confirmed records; not settlement proof</div></article>
    </section>
    <div className="content-grid">
      <section className="panel activity-panel"><div className="panel-heading"><div><h2>Recent activity</h2><p>Your latest contribution and account events</p></div><button className="text-button" onClick={() => window.alert('Activity history will be available when the records API is connected.')}>View history <ArrowRight size={15} /></button></div>{dashboardData?.recent_contributions.length ? <div className="contribution-list">{dashboardData.recent_contributions.slice(0, 5).map(record => <div className="check-row" key={record.id}><span className="check-number">{record.status === 'confirmed' ? <CheckCircle2 size={17} /> : <CalendarClock size={17} />}</span><div><strong>{record.group?.name ?? `Group #${record.thrift_group_id}`}</strong><p>{new Date(record.created_at).toLocaleDateString()} · {record.status}</p></div><div className="contribution-amount"><strong>{new Intl.NumberFormat('en-NG', { style: 'currency', currency: record.currency_code }).format(record.amount_minor / 100)}</strong></div></div>)}</div> : <EmptyPanel icon={ArrowDownLeft} title="No contribution activity yet" body="Your contribution records will appear here when they exist in your groups." />}</section>
      <section className="panel next-panel"><div className="panel-heading"><div><h2>Upcoming contributions</h2><p>Scheduled due dates from your active groups</p></div></div>{dashboardData?.upcoming_obligations.length ? <div className="contribution-list">{dashboardData.upcoming_obligations.slice(0, 5).map(item => <div className="check-row" key={item.id}><span className="check-number"><CalendarClock size={17} /></span><div><strong>{item.group?.name ?? 'Thrift group'}</strong><p>Due {item.due_on ? new Date(`${item.due_on}T00:00:00`).toLocaleDateString() : 'date not set'}</p></div><div className="contribution-amount"><strong>{new Intl.NumberFormat('en-NG', { style: 'currency', currency: item.currency_code }).format(item.expected_amount_minor / 100)}</strong><small>Scheduled</small></div></div>)}</div> : <EmptyPanel icon={CalendarClock} title="No upcoming schedule loaded" body="Upcoming items appear after your organizer creates a schedule and generates due-date cycles. These entries do not initiate collections." />}</section>
    </div>
    <section className="panel assurance"><div className="assurance-icon"><ShieldCheck size={21} /></div><div><strong>Clear records. Accountable collections.</strong><p>Financial entries will clearly distinguish recorded collections, confirmed deposits and reconciled transactions.</p></div></section>
  </>;
}

type AdminDashboardData = {
  administrator: { id: number; name: string; email: string };
  status: string;
  metrics: {
    registered_members: number;
    active_businesses: number;
    active_thrift_groups: number;
    scheduled_cycles: number;
    pending_adjustment_reviews: number;
  };
  recent_audit_events: Array<{ id: number; action: string; actor_name: string | null; subject_name: string | null; created_at: string }>;
  financial_execution_enabled: false;
  message: string;
};

function AdminDashboard({ active }: { active: string }) {
  const [data, setData] = useState<AdminDashboardData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    let cancelled = false;
    const token = sessionStorage.getItem('tcp_access_token') ?? '';
    if (!token) {
      setData(null);
      setError('Sign in with an authorized platform-admin account to view operational metrics.');
      setLoading(false);
      return () => { cancelled = true; };
    }
    setLoading(true);
    fetch('/api/v1/admin/dashboard', { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } })
      .then(async response => {
        if (response.status === 401) throw new Error('Your session expired. Please sign in again.');
        if (response.status === 403) throw new Error('Access denied. This account needs platform-admin status and the platform.users.view permission.');
        if (!response.ok) throw new Error('The protected admin dashboard could not be loaded.');
        return response.json() as Promise<{ data: AdminDashboardData }>;
      })
      .then(result => { if (!cancelled) { setData(result.data); setError(''); } })
      .catch(reason => { if (!cancelled) { setData(null); setError(reason instanceof Error ? reason.message : 'Unable to load authorized admin data.'); } })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, []);

  return <>
    <SectionHeading eyebrow="PLATFORM ADMINISTRATION" title={active === 'Overview' ? 'Operations overview.' : active} description="Protected, read-only operational data from the authenticated platform API." />
    <div className="preview-warning"><ShieldCheck size={19} /><div><strong>Read-only administration</strong><p>{data?.message ?? 'No financial execution, ledger posting, payouts, or provider settlement actions are exposed in this dashboard.'}</p></div></div>
    {loading ? <section className="panel"><EmptyPanel icon={Activity} title="Loading protected data" body="Checking your platform permissions and retrieving current operational counts." /></section>
      : error ? <section className="panel"><EmptyPanel icon={ShieldCheck} title="Admin data unavailable" body={error} /></section>
      : data ? <>
        <div className="admin-api-meta"><span>Authorized as <strong>{data.administrator.name}</strong></span><span className="status-pill status-neutral">Read-only</span></div>
        <section className="stats-grid admin-stats" aria-label="Authorized operational metrics">
          <article className="stat-card"><div className="stat-top"><span>Registered members</span><span className="stat-icon"><Users size={19} /></span></div><div className="stat-value">{data.metrics.registered_members.toLocaleString()}</div><div className="stat-foot muted">All platform accounts</div></article>
          <article className="stat-card"><div className="stat-top"><span>Active businesses</span><span className="stat-icon green"><LayoutDashboard size={19} /></span></div><div className="stat-value">{data.metrics.active_businesses.toLocaleString()}</div><div className="stat-foot muted">Active workspaces</div></article>
          <article className="stat-card"><div className="stat-top"><span>Active thrift groups</span><span className="stat-icon"><Users size={19} /></span></div><div className="stat-value">{data.metrics.active_thrift_groups.toLocaleString()}</div><div className="stat-foot muted">Group records marked active</div></article>
          <article className="stat-card"><div className="stat-top"><span>Scheduled cycles</span><span className="stat-icon amber"><CalendarClock size={19} /></span></div><div className="stat-value">{data.metrics.scheduled_cycles.toLocaleString()}</div><div className="stat-foot muted">No collection initiated</div></article>
          <article className="stat-card"><div className="stat-top"><span>Pending adjustment reviews</span><span className="stat-icon red"><ClipboardCheck size={19} /></span></div><div className="stat-value">{data.metrics.pending_adjustment_reviews.toLocaleString()}</div><div className="stat-foot muted">Require independent review</div></article>
        </section>
        <section className="panel"><div className="panel-heading"><div><h2>Recent platform audit events</h2><p>Administrative activity history, newest first</p></div></div>
          {data.recent_audit_events.length ? <div className="contribution-list">{data.recent_audit_events.map(event => <div className="check-row" key={event.id}><span className="check-number"><ShieldCheck size={17} /></span><div><strong>{event.action.replaceAll('.', ' ')}</strong><p>{event.actor_name ?? 'System/unknown actor'}{event.subject_name ? ` · ${event.subject_name}` : ''}</p></div><div className="contribution-amount"><small>{new Date(event.created_at).toLocaleString()}</small></div></div>)}</div> : <EmptyPanel icon={Activity} title="No platform audit events yet" body="New audited administrative changes will appear here." />}
        </section>
        <section className="panel security-note"><div className="assurance-icon"><ShieldCheck size={21} /></div><div><strong>Financial execution remains disabled</strong><p>Cycle generation only creates due-date records. Correction/reversal approval records a decision and does not mutate contribution records or post financial ledger entries.</p></div></section>
      </> : null}
  </>;
}
