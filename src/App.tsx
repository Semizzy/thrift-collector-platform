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
          <div className="sidebar-profile"><div className="avatar">TC</div><div className="profile-copy"><strong>{page === 'admin' ? 'Platform operator' : 'Your account'}</strong><small>{page === 'admin' ? 'Admin workspace preview' : 'Member workspace'}</small></div><button className="icon-button" aria-label="Account options"><ChevronDown size={17} /></button></div>
        </div>
      </aside>

      <main className="main-area">
        <header className="topbar">
          <div className="topbar-left">
            <button className="icon-button menu-trigger" aria-label="Open navigation" onClick={() => setMenuOpen(true)}><Menu size={21} /></button>
            <div className="breadcrumb"><span>Workspace</span><span className="crumb-slash">/</span><strong>{page === 'admin' ? 'Platform admin' : 'My dashboard'}</strong></div>
          </div>
          <div className="topbar-actions">
            <button className="switcher" onClick={() => navigate(page === 'admin' ? 'user' : 'admin')}><span className="switcher-dot" />{page === 'admin' ? 'User view' : 'Admin preview'}<ArrowRight size={14} /></button>
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
type DashboardSummary = { user: DashboardProfile; active_workspaces_count: number; workspaces: Array<{ id: number; name: string; country_code: string; status: string; role: string }>; active_groups_count: number; groups: Array<{ id: number; business_id: number; name: string; description: string | null; currency_code: string; status: string; role: string; joined_at: string | null }>; confirmed_contribution_totals: Array<{ currency_code: string; total_minor: number; records_count: number }>; recent_contributions: Array<{ id: number; thrift_group_id: number; user_id: number; amount_minor: number; currency_code: string; status: string; collection_method: string; reference: string | null; created_at: string; confirmed_at: string | null; group: { id: number; name: string; currency_code: string } | null }>; financial_data_status: string; message: string };

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
    sessionStorage.removeItem('tcp_access_token'); setToken(''); setProfile(null); setWorkspaceCount(null); setDashboardData(null);
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
    <div className="notice"><div className="notice-symbol"><Activity size={18} /></div><div className="notice-copy"><strong>Account data connected securely</strong><p>Your profile and active workspace count are loaded from the authenticated API. Financial totals remain hidden until verified contribution records exist.</p></div><button className="text-button signout-button" onClick={signOut}><LogOut size={15} /> Sign out</button></div>
    <section className="stats-grid" aria-label="Account summary">
      <article className="stat-card stat-featured"><div className="stat-top"><span>Total confirmed contributions</span><span className="stat-icon"><Wallet size={19} /></span></div><div className="stat-value">{dashboardData?.confirmed_contribution_totals.length ? dashboardData.confirmed_contribution_totals.map(total => `${total.currency_code} ${new Intl.NumberFormat('en-NG', { maximumFractionDigits: 2 }).format(total.total_minor / 100)}`).join(' · ') : '—'}</div><div className="stat-foot"><span className="status-pill status-neutral"><Activity size={13} /> Confirmed records only</span></div></article>
      <article className="stat-card"><div className="stat-top"><span>Active thrift groups</span><span className="stat-icon green"><Users size={19} /></span></div><div className="stat-value">{dashboardData?.active_groups_count ?? "—"}</div><div className="stat-foot muted">Groups where you have active membership</div></article>
      <article className="stat-card"><div className="stat-top"><span>Upcoming contributions</span><span className="stat-icon amber"><CalendarClock size={19} /></span></div><div className="stat-value">—</div><div className="stat-foot muted">Due dates from your group cycles</div></article>
      <article className="stat-card"><div className="stat-top"><span>Confirmed records</span><span className="stat-icon green"><CheckCircle2 size={19} /></span></div><div className="stat-value">{dashboardData?.confirmed_contribution_totals.reduce((sum, total) => sum + total.records_count, 0) ?? '—'}</div><div className="stat-foot muted">Confirmed records; not settlement proof</div></article>
    </section>
    <div className="content-grid">
      <section className="panel activity-panel"><div className="panel-heading"><div><h2>Recent activity</h2><p>Your latest contribution and account events</p></div><button className="text-button" onClick={() => window.alert('Activity history will be available when the records API is connected.')}>View history <ArrowRight size={15} /></button></div>{dashboardData?.recent_contributions.length ? <div className="contribution-list">{dashboardData.recent_contributions.slice(0, 5).map(record => <div className="check-row" key={record.id}><span className="check-number">{record.status === 'confirmed' ? <CheckCircle2 size={17} /> : <CalendarClock size={17} />}</span><div><strong>{record.group?.name ?? `Group #${record.thrift_group_id}`}</strong><p>{new Date(record.created_at).toLocaleDateString()} · {record.status}</p></div><div className="contribution-amount"><strong>{new Intl.NumberFormat('en-NG', { style: 'currency', currency: record.currency_code }).format(record.amount_minor / 100)}</strong></div></div>)}</div> : <EmptyPanel icon={ArrowDownLeft} title="No contribution activity yet" body="Your contribution records will appear here when they exist in your groups." />}</section>
      <section className="panel next-panel"><div className="panel-heading"><div><h2>Next steps</h2><p>Get your workspace ready</p></div></div><div className="checklist"><div className="check-row"><span className="check-number">1</span><div><strong>Complete your profile</strong><p>Keep your contact details current.</p></div><ArrowRight size={16} /></div><div className="check-row"><span className="check-number">2</span><div><strong>Review group invitations</strong><p>Check invitations from your organizer.</p></div><ArrowRight size={16} /></div><div className="check-row"><span className="check-number">3</span><div><strong>Learn about confirmations</strong><p>A recorded collection is not the same as settled funds.</p></div><ArrowRight size={16} /></div></div></section>
    </div>
    <section className="panel assurance"><div className="assurance-icon"><ShieldCheck size={21} /></div><div><strong>Clear records. Accountable collections.</strong><p>Financial entries will clearly distinguish recorded collections, confirmed deposits and reconciled transactions.</p></div></section>
  </>;
}

function AdminDashboard({ active }: { active: string }) {
  return <>
    <SectionHeading eyebrow="PLATFORM ADMINISTRATION" title={active === 'Overview' ? 'Operations overview.' : active} description="A responsive admin workspace concept for secure platform operations." />
    <div className="preview-warning"><ShieldCheck size={19} /><div><strong>Admin interface preview — access control not yet connected</strong><p>This screen contains no live member or financial data. Do not use it for administration until platform-admin authorization and protected API endpoints are implemented.</p></div></div>
    <section className="stats-grid admin-stats" aria-label="Administrative metric placeholders">
      <article className="stat-card"><div className="stat-top"><span>Registered members</span><span className="stat-icon"><Users size={19} /></span></div><div className="stat-value">—</div><div className="stat-foot muted">Awaiting authorized API</div></article>
      <article className="stat-card"><div className="stat-top"><span>Active businesses</span><span className="stat-icon green"><LayoutDashboard size={19} /></span></div><div className="stat-value">—</div><div className="stat-foot muted">Awaiting authorized API</div></article>
      <article className="stat-card"><div className="stat-top"><span>Reconciliation exceptions</span><span className="stat-icon amber"><ClipboardCheck size={19} /></span></div><div className="stat-value">—</div><div className="stat-foot muted">Awaiting authorized API</div></article>
      <article className="stat-card"><div className="stat-top"><span>Pending approvals</span><span className="stat-icon red"><CreditCard size={19} /></span></div><div className="stat-value">—</div><div className="stat-foot muted">Awaiting authorized API</div></article>
    </section>
    <div className="content-grid admin-content-grid">
      <section className="panel"><div className="panel-heading"><div><h2>Operational health</h2><p>Service health and background processing</p></div></div><EmptyPanel icon={Activity} title="Health checks not connected" body="Live service status will be shown after an authorized health endpoint is available." /></section>
      <section className="panel"><div className="panel-heading"><div><h2>Needs attention</h2><p>Review queue and risk signals</p></div></div><EmptyPanel icon={ClipboardCheck} title="No live queue loaded" body="Approval and reconciliation items must come from protected, permission-checked endpoints." /></section>
    </div>
    <section className="panel security-note"><div className="assurance-icon"><ShieldCheck size={21} /></div><div><strong>Security before administration</strong><p>Platform-admin roles, server-side route guards, audit events and maker-checker controls must be implemented before any privileged action is enabled.</p></div></section>
  </>;
}
