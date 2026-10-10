import { useEffect, useState } from 'react';
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

function UserDashboard({ active }: { active: string }) {
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
    <SectionHeading eyebrow="MEMBER WORKSPACE" title="Your savings, at a glance." description="Welcome to your personal thrift workspace. Your confirmed activity will appear here." />
    <div className="notice"><div className="notice-symbol"><Activity size={18} /></div><div><strong>Your account overview is being connected</strong><p>We will show verified account totals and group activity here. No financial figures are estimated or fabricated.</p></div></div>
    <section className="stats-grid" aria-label="Account summary">
      <article className="stat-card stat-featured"><div className="stat-top"><span>Total contributions recorded</span><span className="stat-icon"><Wallet size={19} /></span></div><div className="stat-value">— <small>NGN</small></div><div className="stat-foot"><span className="status-pill status-neutral"><Activity size={13} /> Awaiting account data</span></div></article>
      <article className="stat-card"><div className="stat-top"><span>Active groups</span><span className="stat-icon green"><Users size={19} /></span></div><div className="stat-value">—</div><div className="stat-foot muted">Groups linked to your account</div></article>
      <article className="stat-card"><div className="stat-top"><span>Upcoming contributions</span><span className="stat-icon amber"><CalendarClock size={19} /></span></div><div className="stat-value">—</div><div className="stat-foot muted">Due dates from your group cycles</div></article>
      <article className="stat-card"><div className="stat-top"><span>Confirmed records</span><span className="stat-icon green"><CheckCircle2 size={19} /></span></div><div className="stat-value">—</div><div className="stat-foot muted">Only confirmed records count here</div></article>
    </section>
    <div className="content-grid">
      <section className="panel activity-panel"><div className="panel-heading"><div><h2>Recent activity</h2><p>Your latest contribution and account events</p></div><button className="text-button" onClick={() => window.alert('Activity history will be available when the records API is connected.')}>View history <ArrowRight size={15} /></button></div><EmptyPanel icon={ArrowDownLeft} title="No activity to show yet" body="When your account has activity, the latest verified records will appear here." /></section>
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
