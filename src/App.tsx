import { useEffect, useState, type FormEvent } from 'react';
import {
  Activity, AlertTriangle, ArrowDownLeft, ArrowRight, Bell, CalendarClock, CheckCircle2, Copy, Landmark, Plus, RefreshCw,
  ChevronDown, CircleHelp, ClipboardCheck, FileText, Home,
  LayoutDashboard, LogOut, Menu, Moon, Search, Settings, ShieldCheck,
  Sun, Users, Wallet, X, type LucideIcon,
} from 'lucide-react';

type Page = 'user' | 'admin';
type NavItem = { label: string; icon: LucideIcon };

const userNav: NavItem[] = [
  { label: 'Overview', icon: Home },
  { label: 'My groups', icon: Users },
  { label: 'Contributions', icon: Wallet },
  { label: 'Bank accounts', icon: Landmark },
  { label: 'Deposit review', icon: ClipboardCheck },
  { label: 'Account operations', icon: AlertTriangle },
  { label: 'Security & PIN', icon: ShieldCheck },
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
  const [canReviewDeposits, setCanReviewDeposits] = useState(false);

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

  const items = page === 'admin' ? adminNav : userNav.filter(item => (!['Deposit review', 'Account operations'].includes(item.label) || canReviewDeposits));

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
          {page === 'admin' ? <AdminDashboard active={active} /> : <UserDashboard active={active} onReviewerAccessChange={setCanReviewDeposits} />}
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
type DepositAccount = { id: number; provider: string; bank_code: string; bank_name: string; account_number: string | null; account_name: string | null; currency_code: string; status: string; assigned_at: string | null };
type DepositBank = { code: string; name: string; provider: string; currency_code: string };
type PendingAccountDiagnostic = { id: number; bank_name: string; bank_code: string; currency_code: string; status: string; created_at: string | null; age_minutes: number; recovery_attempts: number; last_recovery_at: string | null; last_recovery_result: string | null; has_provider_customer_reference: boolean; has_provider_account_reference: boolean; diagnostic_code: string; recommended_action: string; escalation: { id: number; status: string; created_at: string } | null };
type ProviderDeposit = { id: number; provider: string; provider_transaction_id: string; provider_reference: string | null; amount_minor: number; currency_code: string; status: string; occurred_at: string | null; created_at: string; user_id: number | null; account?: { bank_name?: string; account_name_ciphertext?: string | null } | null; safe_metadata?: { channel?: string; account_matched?: boolean } | null };
type DashboardSummary = { user: DashboardProfile; active_workspaces_count: number; workspaces: Array<{ id: number; name: string; country_code: string; status: string; role: string }>; active_groups_count: number; groups: Array<{ id: number; business_id: number; name: string; description: string | null; currency_code: string; status: string; role: string; joined_at: string | null }>; confirmed_contribution_totals: Array<{ currency_code: string; total_minor: number; records_count: number }>; recent_contributions: Array<{ id: number; thrift_group_id: number; user_id: number; amount_minor: number; currency_code: string; status: string; collection_method: string; reference: string | null; created_at: string; confirmed_at: string | null; group: { id: number; name: string; currency_code: string } | null }>; upcoming_obligations_count: number; upcoming_obligations: Array<{ id: number; status: string; expected_amount_minor: number; currency_code: string; due_on: string | null; group: { id: number; name: string } | null }>; financial_data_status: string; message: string };

function UserDashboard({ active, onReviewerAccessChange }: { active: string; onReviewerAccessChange: (allowed: boolean) => void }) {
  const [token, setToken] = useState(() => sessionStorage.getItem('tcp_access_token') ?? '');
  const [profile, setProfile] = useState<DashboardProfile | null>(null);
  const [dashboardData, setDashboardData] = useState<DashboardSummary | null>(null);
  const [authLoading, setAuthLoading] = useState(Boolean(token));
  const [authError, setAuthError] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [selectedBusinessId, setSelectedBusinessId] = useState('');
  const [depositAccounts, setDepositAccounts] = useState<DepositAccount[]>([]);
  const [depositBanks, setDepositBanks] = useState<DepositBank[]>([]);
  const [accountLoading, setAccountLoading] = useState(false);
  const [banksLoading, setBanksLoading] = useState(false);
  const [accountCreating, setAccountCreating] = useState(false);
  const [selectedBankCode, setSelectedBankCode] = useState('');
  const [accountError, setAccountError] = useState('');
  const [accountMessage, setAccountMessage] = useState('');
  const [bankError, setBankError] = useState('');
  const [providerDeposits, setProviderDeposits] = useState<ProviderDeposit[]>([]);
  const [reviewLoading, setReviewLoading] = useState(false);
  const [reviewError, setReviewError] = useState('');
  const [reviewMessage, setReviewMessage] = useState('');
  const [reviewReason, setReviewReason] = useState('');
  const [reviewingDepositId, setReviewingDepositId] = useState<number | null>(null);
  const [reviewRefreshKey, setReviewRefreshKey] = useState(0);
  const [pendingDiagnostics, setPendingDiagnostics] = useState<PendingAccountDiagnostic[]>([]);
  const [diagnosticsLoading, setDiagnosticsLoading] = useState(false);
  const [diagnosticsError, setDiagnosticsError] = useState('');
  const [diagnosticsMessage, setDiagnosticsMessage] = useState('');
  const [escalationReason, setEscalationReason] = useState('');
  const [escalatingAccountId, setEscalatingAccountId] = useState<number | null>(null);
  const [diagnosticsRefreshKey, setDiagnosticsRefreshKey] = useState(0);
  const [pinConfigured, setPinConfigured] = useState(false);
  const [pinSetAt, setPinSetAt] = useState<string | null>(null);
  const [pinValue, setPinValue] = useState('');
  const [pinCurrent, setPinCurrent] = useState('');
  const [pinPassword, setPinPassword] = useState('');
  const [pinOtp, setPinOtp] = useState('');
  const [pinOtpSending, setPinOtpSending] = useState(false);
  const [pinError, setPinError] = useState('');
  const [pinMessage, setPinMessage] = useState('');
  const [pinSaving, setPinSaving] = useState(false);

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
        if (!cancelled) { setProfile(result.data.user); setDashboardData(result.data); setSelectedBusinessId(current => current || String((result.data.workspaces.find(workspace => workspace.status === 'active') ?? result.data.workspaces[0])?.id ?? '')); setAuthError(''); }
      })
      .catch(error => {
        if (!cancelled) { sessionStorage.removeItem('tcp_access_token'); setToken(''); setAuthError(error instanceof Error ? error.message : 'Please sign in to continue.'); }
      })
      .finally(() => { if (!cancelled) setAuthLoading(false); });
    return () => { cancelled = true; };
  }, [token]);

  useEffect(() => {
    const role = dashboardData?.workspaces.find(workspace => String(workspace.id) === selectedBusinessId)?.role;
    onReviewerAccessChange(['business_owner', 'business_manager', 'auditor'].includes(role ?? ''));
  }, [dashboardData, selectedBusinessId, onReviewerAccessChange]);

  useEffect(() => {
    if (!token || !selectedBusinessId) {
      setDepositAccounts([]);
      setDepositBanks([]);
      return;
    }
    let cancelled = false;
    const headers = { Authorization: 'Bearer ' + token, Accept: 'application/json', 'X-Business-Id': selectedBusinessId };
    setAccountLoading(true);
    setBanksLoading(true);
    setAccountError('');
    setBankError('');
    fetch('/api/v1/business/payments/deposit-accounts', { headers })
      .then(async response => {
        const result = await response.json();
        if (!response.ok) throw new Error(result.message ?? 'Could not load deposit accounts.');
        return result as { data: DepositAccount[] };
      })
      .then(result => { if (!cancelled) setDepositAccounts(result.data); })
      .catch(error => { if (!cancelled) setAccountError(error instanceof Error ? error.message : 'Could not load deposit accounts.'); })
      .finally(() => { if (!cancelled) setAccountLoading(false); });
    fetch('/api/v1/business/payments/deposit-accounts/banks', { headers })
      .then(async response => {
        const result = await response.json();
        if (!response.ok) throw new Error(result.message ?? 'Bank choices are unavailable.');
        return result as { data: DepositBank[] };
      })
      .then(result => { if (!cancelled) { setDepositBanks(result.data); setSelectedBankCode(current => current || result.data[0]?.code || ''); } })
      .catch(error => { if (!cancelled) setBankError(error instanceof Error ? error.message : 'Bank choices are unavailable.'); })
      .finally(() => { if (!cancelled) setBanksLoading(false); });
    return () => { cancelled = true; };
  }, [token, selectedBusinessId]);

  useEffect(() => {
    if (!token || !selectedBusinessId || active !== 'Deposit review') return;
    let cancelled = false;
    setReviewLoading(true); setReviewError('');
    fetch('/api/v1/business/payments/provider-deposits', {
      headers: { Authorization: 'Bearer ' + token, Accept: 'application/json', 'X-Business-Id': selectedBusinessId },
    }).then(async response => {
      const result = await response.json();
      if (!response.ok) throw new Error(result.message ?? (response.status === 403 ? 'Your business role does not have reconciliation review permission.' : 'Could not load provider deposit review queue.'));
      return result as { data: { data: ProviderDeposit[] } };
    }).then(result => { if (!cancelled) setProviderDeposits(result.data.data); })
      .catch(error => { if (!cancelled) { setProviderDeposits([]); setReviewError(error instanceof Error ? error.message : 'Could not load provider deposit review queue.'); } })
      .finally(() => { if (!cancelled) setReviewLoading(false); });
    return () => { cancelled = true; };
  }, [token, selectedBusinessId, active, reviewRefreshKey]);

  useEffect(() => {
    if (!token || !selectedBusinessId || active !== 'Account operations') return;
    let cancelled = false;
    setDiagnosticsLoading(true); setDiagnosticsError('');
    fetch('/api/v1/business/payments/deposit-accounts/operations', {
      headers: { Authorization: 'Bearer ' + token, Accept: 'application/json', 'X-Business-Id': selectedBusinessId },
    }).then(async response => {
      const result = await response.json();
      if (!response.ok) throw new Error(result.message ?? 'Could not load pending account diagnostics.');
      return result as { data: PendingAccountDiagnostic[] };
    }).then(result => { if (!cancelled) setPendingDiagnostics(result.data); })
      .catch(error => { if (!cancelled) { setPendingDiagnostics([]); setDiagnosticsError(error instanceof Error ? error.message : 'Could not load pending account diagnostics.'); } })
      .finally(() => { if (!cancelled) setDiagnosticsLoading(false); });
    return () => { cancelled = true; };
  }, [token, selectedBusinessId, active, diagnosticsRefreshKey]);

  async function escalatePendingAccount(accountId: number) {
    if (!token || !selectedBusinessId || escalationReason.trim().length < 10) {
      setDiagnosticsError('Describe the issue in at least 10 characters before escalating.');
      return;
    }
    setEscalatingAccountId(accountId); setDiagnosticsError(''); setDiagnosticsMessage('');
    try {
      const response = await fetch('/api/v1/business/payments/deposit-accounts/' + accountId + '/escalate', {
        method: 'POST',
        headers: { Authorization: 'Bearer ' + token, Accept: 'application/json', 'Content-Type': 'application/json', 'X-Business-Id': selectedBusinessId },
        body: JSON.stringify({ reason: escalationReason.trim() }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message ?? 'Could not create support escalation.');
      setDiagnosticsMessage(result.message ?? 'Support escalation recorded.');
      setEscalationReason('');
      setDiagnosticsRefreshKey(value => value + 1);
    } catch (error) {
      setDiagnosticsError(error instanceof Error ? error.message : 'Could not create support escalation.');
    } finally {
      setEscalatingAccountId(null);
    }
  }

  async function reviewProviderDeposit(eventId: number, decision: 'confirm_evidence' | 'mark_conflict' | 'ignore') {
    if (!token || !selectedBusinessId || reviewReason.trim().length < 8) {
      setReviewError('Enter a review reason of at least 8 characters before recording a decision.');
      return;
    }
    setReviewingDepositId(eventId); setReviewError(''); setReviewMessage('');
    try {
      const response = await fetch('/api/v1/business/payments/provider-deposits/' + eventId + '/review', {
        method: 'POST',
        headers: { Authorization: 'Bearer ' + token, Accept: 'application/json', 'Content-Type': 'application/json', 'X-Business-Id': selectedBusinessId },
        body: JSON.stringify({ decision, reason: reviewReason.trim() }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message ?? 'Could not record the review decision.');
      setProviderDeposits(current => current.filter(item => item.id !== eventId));
      setReviewReason('');
      setReviewMessage(result.message ?? 'Review decision recorded. No financial balance was changed.');
    } catch (error) {
      setReviewError(error instanceof Error ? error.message : 'Could not record the review decision.');
    } finally {
      setReviewingDepositId(null);
    }
  }

  async function addDepositAccount(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!token || !selectedBusinessId || !selectedBankCode) return;
    setAccountCreating(true); setAccountError(''); setAccountMessage('');
    try {
      const response = await fetch('/api/v1/business/payments/deposit-accounts', {
        method: 'POST',
        headers: { Authorization: 'Bearer ' + token, Accept: 'application/json', 'Content-Type': 'application/json', 'X-Business-Id': selectedBusinessId },
        body: JSON.stringify({ bank_code: selectedBankCode, currency_code: 'NGN', client_reference: 'DVA-' + crypto.randomUUID(), consent: true }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message ?? 'Could not request a dedicated deposit account.');
      setAccountMessage(result.message ?? 'Account request submitted.');
      const refreshed = await fetch('/api/v1/business/payments/deposit-accounts', { headers: { Authorization: 'Bearer ' + token, Accept: 'application/json', 'X-Business-Id': selectedBusinessId } });
      if (refreshed.ok) { const list = await refreshed.json(); setDepositAccounts(list.data); }
    } catch (error) {
      setAccountError(error instanceof Error ? error.message : 'Could not request a dedicated deposit account.');
    } finally {
      setAccountCreating(false);
    }
  }

  async function copyDepositAccount(number: string) {
    try {
      await navigator.clipboard.writeText(number);
      setAccountMessage('Account number copied.');
    } catch {
      setAccountError('Copy was unavailable in this browser. Please select the account number and copy it manually.');
    }
  }

  useEffect(() => {
    if (!token || active !== 'Security & PIN') return;
    let cancelled = false;
    fetch('/api/v1/auth/transaction-pin', { headers: { Authorization: 'Bearer ' + token, Accept: 'application/json' } })
      .then(async response => { const result = await response.json(); if (!response.ok) throw new Error(result.message ?? 'Could not load transaction PIN status.'); return result as { data: { configured: boolean; set_at: string | null } }; })
      .then(result => { if (!cancelled) { setPinConfigured(result.data.configured); setPinSetAt(result.data.set_at); setPinError(''); } })
      .catch(error => { if (!cancelled) setPinError(error instanceof Error ? error.message : 'Could not load transaction PIN status.'); });
    return () => { cancelled = true; };
  }, [token, active]);

  async function sendTransactionPinOtp() {
    setPinError(''); setPinMessage(''); setPinOtpSending(true);
    try {
      const response = await fetch('/api/v1/auth/transaction-pin/challenge', {
        method: 'POST',
        headers: { Authorization: 'Bearer ' + token, Accept: 'application/json' },
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message ?? 'Could not send verification code.');
      setPinMessage(result.message ?? 'Verification code sent to your verified email.');
    } catch (error) {
      setPinError(error instanceof Error ? error.message : 'Could not send verification code.');
    } finally {
      setPinOtpSending(false);
    }
  }

  async function saveTransactionPin(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setPinError(''); setPinMessage('');
    if (pinValue.length !== 4 || !/^\d{4}$/.test(pinValue)) { setPinError('Enter exactly four digits.'); return; }
    setPinSaving(true);
    try {
      const response = await fetch('/api/v1/auth/transaction-pin', {
        method: 'POST',
        headers: { Authorization: 'Bearer ' + token, Accept: 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({ pin: pinValue, current_password: pinPassword, otp_code: pinOtp, ...(pinConfigured ? { current_pin: pinCurrent } : {}) }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message ?? 'Could not save transaction PIN.');
      setPinConfigured(true); setPinSetAt(result.data?.set_at ?? new Date().toISOString());
      setPinValue(''); setPinCurrent(''); setPinPassword(''); setPinOtp('');
      setPinMessage(result.message ?? 'Transaction PIN saved securely.');
    } catch (error) {
      setPinError(error instanceof Error ? error.message : 'Could not save transaction PIN.');
    } finally {
      setPinSaving(false);
    }
  }

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

  const providerDepositReviewPanel = <section className="panel deposit-review-panel">
    <div className="panel-heading"><div><h2>Provider deposit evidence</h2><p>Review signed Paystack transfer notifications against independent bank or provider records. Decisions do not post money.</p></div><button className="text-button" onClick={() => setReviewRefreshKey(v => v + 1)}><RefreshCw size={15} /> Refresh</button></div>
    {reviewError && <p className="auth-error" role="alert">{reviewError}</p>}
    {reviewMessage && <p className="account-success" role="status">{reviewMessage}</p>}
    {reviewLoading ? <p className="muted">Loading provider evidence…</p> : providerDeposits.length ? <div className="deposit-review-list">
      {providerDeposits.map(item => <article className="deposit-review-card" key={item.id}>
        <div className="review-event-heading"><div><strong>{item.provider.toUpperCase()} transfer · #{item.id}</strong><p>{item.provider_reference || item.provider_transaction_id}</p></div><span className={'status-pill ' + (item.status === 'needs_review' ? 'status-neutral' : 'status-warning')}>{item.status.replaceAll('_', ' ')}</span></div>
        <div className="review-event-meta"><strong>{new Intl.NumberFormat('en-NG', { style: 'currency', currency: item.currency_code }).format(item.amount_minor / 100)}</strong><span>{item.occurred_at ? new Date(item.occurred_at).toLocaleString() : new Date(item.created_at).toLocaleString()}</span></div>
        <p className="muted review-event-note">Channel: {item.safe_metadata?.channel || 'not supplied'} · Account match: {item.safe_metadata?.account_matched ? 'matched' : 'unmatched'}</p>
        <label className="review-reason-label">Evidence and decision reason
          <textarea value={reviewReason} onChange={event => setReviewReason(event.target.value)} minLength={8} maxLength={500} rows={2} placeholder="Explain what you checked against the independent provider/bank record…" />
        </label>
        <div className="review-decision-actions">
          <button className="button button-primary" disabled={reviewingDepositId !== null} onClick={() => void reviewProviderDeposit(item.id, 'confirm_evidence')}>{reviewingDepositId === item.id ? 'Saving…' : 'Confirm evidence'}</button>
          <button className="button button-secondary" disabled={reviewingDepositId !== null} onClick={() => void reviewProviderDeposit(item.id, 'mark_conflict')}>Mark conflict</button>
          <button className="text-button" disabled={reviewingDepositId !== null} onClick={() => void reviewProviderDeposit(item.id, 'ignore')}>Ignore</button>
        </div>
      </article>)}
    </div> : <div className="deposit-empty"><ClipboardCheck size={23} /><strong>No pending provider deposits</strong><p>Unreviewed signed provider notifications will appear here for authorized business reviewers.</p></div>}
    <p className="deposit-consent-note">A review records evidence status only. It does not verify final settlement or credit a member balance. The account holder cannot review their own event.</p>
  </section>;

  const transactionPinPanel = <section className="panel transaction-pin-panel">
    <div className="panel-heading"><div><h2>Transaction PIN</h2><p>Protect payment requests with a four-digit PIN. The PIN is stored as a password hash and is never displayed again.</p></div><ShieldCheck size={22} /></div>
    <div className="pin-status-row"><span className={'status-pill ' + (pinConfigured ? 'status-neutral' : 'status-warning')}>{pinConfigured ? 'PIN configured' : 'PIN setup required'}</span>{pinSetAt && <span className="muted">Last set {new Date(pinSetAt).toLocaleDateString()}</span>}</div>
    {pinError && <p className="auth-error" role="alert">{pinError}</p>}
    {pinMessage && <p className="account-success" role="status">{pinMessage}</p>}
    <form className="pin-form" onSubmit={saveTransactionPin}>
      {pinConfigured && <><label htmlFor="pin-current">Current transaction PIN</label><input id="pin-current" inputMode="numeric" autoComplete="off" type="password" pattern="[0-9]{4}" maxLength={4} required value={pinCurrent} onChange={event => setPinCurrent(event.target.value.replace(/\D/g, '').slice(0, 4))} /></>}
      <label htmlFor="pin-new">{pinConfigured ? 'New transaction PIN' : 'Create transaction PIN'}</label>
      <input id="pin-new" inputMode="numeric" autoComplete="new-password" type="password" pattern="[0-9]{4}" maxLength={4} required value={pinValue} onChange={event => setPinValue(event.target.value.replace(/\D/g, '').slice(0, 4))} />
      <label htmlFor="pin-otp">Email verification code</label>
      <div className="pin-otp-row"><input id="pin-otp" inputMode="numeric" autoComplete="one-time-code" pattern="[0-9]{6}" maxLength={6} required value={pinOtp} onChange={event => setPinOtp(event.target.value.replace(/\\D/g, '').slice(0, 6))} /><button className="button button-secondary" type="button" onClick={() => void sendTransactionPinOtp()} disabled={pinOtpSending}>{pinOtpSending ? 'Sending…' : 'Email me a code'}</button></div>
      <label htmlFor="pin-password">Confirm your account password</label>
      <input id="pin-password" autoComplete="current-password" type="password" required value={pinPassword} onChange={event => setPinPassword(event.target.value)} />
      <button className="button button-primary" type="submit" disabled={pinSaving}>{pinSaving ? 'Saving securely…' : pinConfigured ? 'Change transaction PIN' : 'Set transaction PIN'}</button>
    </form>
    <p className="deposit-consent-note">Payment requests require this PIN and a transaction purpose. Five incorrect attempts trigger a 15-minute transaction lock. Choose a non-obvious PIN. PIN setup or changes require a code sent to your verified email, password re-authentication, and your existing PIN when changing it. Codes expire after 10 minutes.</p>
  </section>;

  const accountOperationsPanel = <section className="panel deposit-review-panel">
    <div className="panel-heading"><div><h2>Pending assignment diagnostics</h2><p>Accounts pending for at least an hour or after three recovery attempts. No account numbers, names, credentials, or PIN values are shown.</p></div><button className="text-button" onClick={() => setDiagnosticsRefreshKey(value => value + 1)}><RefreshCw size={15} /> Refresh</button></div>
    {diagnosticsError && <p className="auth-error" role="alert">{diagnosticsError}</p>}
    {diagnosticsMessage && <p className="account-success" role="status">{diagnosticsMessage}</p>}
    {diagnosticsLoading ? <p className="muted">Checking safe provider diagnostics…</p> : pendingDiagnostics.length ? <div className="deposit-review-list">
      {pendingDiagnostics.map(item => <article className="deposit-review-card" key={item.id}>
        <div className="review-event-heading"><div><strong>{item.bank_name} · Account request #{item.id}</strong><p>{item.diagnostic_code.replaceAll('_', ' ')}</p></div><span className="status-pill status-warning">{item.recovery_attempts} requery attempts</span></div>
        <div className="review-event-meta"><strong>{Math.floor(item.age_minutes / 60)}h {item.age_minutes % 60}m pending</strong><span>Last check: {item.last_recovery_at ? new Date(item.last_recovery_at).toLocaleString() : 'Not checked yet'}</span></div>
        <p className="muted review-event-note">Customer reference: {item.has_provider_customer_reference ? 'present' : 'missing'} · Provider account reference: {item.has_provider_account_reference ? 'present' : 'not assigned'} · Last result: {(item.last_recovery_result || 'not attempted').replaceAll('_', ' ')}</p>
        <p className="review-event-note">{item.recommended_action}</p>
        {item.escalation ? <p className="account-success" role="status">Escalation #{item.escalation.id} · {item.escalation.status}</p> : <div className="review-escalation-form">
          <label className="review-reason-label">Support escalation reason
            <textarea value={escalationReason} onChange={event => setEscalationReason(event.target.value)} minLength={10} maxLength={1000} rows={2} placeholder="Describe the pending assignment and checks already completed…" />
          </label>
          <button className="button button-secondary" disabled={escalatingAccountId !== null} onClick={() => void escalatePendingAccount(item.id)}>{escalatingAccountId === item.id ? 'Escalating…' : 'Escalate to support'}</button>
        </div>}
      </article>)}
    </div> : <div className="deposit-empty"><CheckCircle2 size={23} /><strong>No stuck account assignments</strong><p>Accounts with repeated retries or a pending age over one hour will appear here.</p></div>}
    <p className="deposit-consent-note">Escalation records an audited support request for the business team. It does not retry assignment, create a replacement account, verify settlement, or post financial entries.</p>
  </section>;

  const depositAccountsPanel = <section className="panel deposit-accounts-panel">
    <div className="panel-heading"><div><h2>Your dedicated deposit accounts</h2><p>Keep two or more bank accounts if supported by the provider. Deposits remain pending reconciliation until independently reviewed.</p></div><button className="text-button" onClick={() => { setAccountMessage(''); setAccountError(''); setAccountLoading(true); fetch('/api/v1/business/payments/deposit-accounts', { headers: { Authorization: 'Bearer ' + token, Accept: 'application/json', 'X-Business-Id': selectedBusinessId } }).then(async response => { const result = await response.json(); if (!response.ok) throw new Error(result.message || 'Could not refresh accounts.'); setDepositAccounts(result.data); }).catch(error => setAccountError(error instanceof Error ? error.message : 'Could not refresh accounts.')).finally(() => setAccountLoading(false)); }}><RefreshCw size={15} /> Refresh</button></div>
    {dashboardData?.workspaces.length ? <label className="business-select-label">Workspace
      <select value={selectedBusinessId} onChange={event => { setSelectedBusinessId(event.target.value); setAccountMessage(''); setAccountError(''); }} aria-label="Choose workspace for deposit accounts">
        {dashboardData.workspaces.map(workspace => <option key={workspace.id} value={String(workspace.id)}>{workspace.name} · {workspace.country_code}</option>)}
      </select>
    </label> : <p className="muted">Create or join a business workspace before requesting a dedicated account.</p>}
    {accountError && <p className="auth-error" role="alert">{accountError}</p>}
    {accountMessage && <p className="account-success" role="status">{accountMessage}</p>}
    {accountLoading ? <p className="muted">Loading your accounts…</p> : depositAccounts.length ? <div className="deposit-account-list">
      {depositAccounts.map(account => <article className="deposit-account-card" key={account.id}>
        <div className="deposit-bank-mark"><Landmark size={20} /></div><div className="deposit-account-details"><strong>{account.bank_name}</strong><span>{account.account_name || 'Account assignment pending'}</span><code>{account.account_number || 'Awaiting provider assignment'}</code><small>{account.currency_code} · {account.status.replaceAll('_', ' ')}</small></div>
        {account.account_number && <button className="icon-button account-copy" aria-label={'Copy ' + account.bank_name + ' account number'} onClick={() => void copyDepositAccount(account.account_number!)}><Copy size={17} /></button>}
      </article>)}
    </div> : <div className="deposit-empty"><Landmark size={22} /><strong>No dedicated accounts yet</strong><p>Request an account below. If assignment is asynchronous, this list will show its current status.</p></div>}
    <form className="deposit-account-form" onSubmit={addDepositAccount}>
      <label htmlFor="deposit-bank">Add another bank account</label>
      <div className="deposit-account-controls">
        <select id="deposit-bank" required value={selectedBankCode} onChange={event => setSelectedBankCode(event.target.value)} disabled={banksLoading || !depositBanks.length || accountCreating}>
          <option value="">{banksLoading ? 'Loading supported banks…' : 'Choose a supported bank'}</option>
          {depositBanks.map(bank => <option key={bank.code} value={bank.code}>{bank.name}</option>)}
        </select>
        <button className="button button-primary" type="submit" disabled={accountCreating || banksLoading || !selectedBusinessId || !selectedBankCode}><Plus size={16} />{accountCreating ? 'Requesting…' : 'Add account'}</button>
      </div>
      {bankError && <p className="muted">{bankError}</p>}
      <p className="deposit-consent-note">By requesting an account, you consent to the provider processing the identity details needed to assign it. Available banks depend on provider eligibility and approval.</p>
    </form>
  </section>;

  if (active === 'My groups' && dashboardData) return <><SectionHeading eyebrow="MEMBER WORKSPACE" title="My groups" description="Thrift groups where you have active membership." />{dashboardData.groups.length ? <div className="content-grid">{dashboardData.groups.map(group => <section className="panel" key={group.id}><div className="panel-heading"><div><h2>{group.name}</h2><p>{group.description || 'No group description provided.'}</p></div><span className="status-pill status-neutral">{group.status}</span></div><div className="stat-foot muted">{group.currency_code} · {group.role} · Group #{group.id}</div></section>)}</div> : <EmptyPanel icon={Users} title="No active thrift groups yet" body="When you join a thrift group, it will appear here." />}</>;
  if (active === 'Contributions' && dashboardData) return <><SectionHeading eyebrow="MEMBER WORKSPACE" title="Contributions" description="Your contribution records from groups you belong to." />{dashboardData.recent_contributions.length ? <section className="panel"><div className="panel-heading"><div><h2>Recent contribution records</h2><p>Record confirmation is not proof of bank deposit or provider settlement.</p></div></div><div className="contribution-list">{dashboardData.recent_contributions.map(record => <div className="check-row" key={record.id}><span className="check-number">{record.status === 'confirmed' ? <CheckCircle2 size={17} /> : <CalendarClock size={17} />}</span><div><strong>{record.group?.name ?? `Group #${record.thrift_group_id}`}</strong><p>{new Date(record.created_at).toLocaleDateString()} · {record.collection_method.replace('_', ' ')}</p></div><div className="contribution-amount"><strong>{new Intl.NumberFormat('en-NG', { style: 'currency', currency: record.currency_code }).format(record.amount_minor / 100)}</strong><small>{record.status}</small></div></div>)}</div></section> : <EmptyPanel icon={Wallet} title="No contribution records yet" body="Records will appear here when a permitted organizer or collector records a contribution for you." />}</>;

  if (active === 'Deposit review') return <><SectionHeading eyebrow="BUSINESS WORKSPACE · AUTHORIZED REVIEWERS" title="Deposit review" description="Independently review provider notifications and record an auditable evidence decision." />{providerDepositReviewPanel}</>;

  if (active === 'Account operations') return <><SectionHeading eyebrow="BUSINESS WORKSPACE · AUTHORIZED OPERATIONS" title="Pending account operations" description="Diagnose repeated or long-running provider assignments and escalate them for support follow-up." />{accountOperationsPanel}</>;

  if (active === 'Security & PIN') return <><SectionHeading eyebrow="ACCOUNT SECURITY" title="Security & transaction PIN" description="Set or update the PIN used to confirm payment requests." />{transactionPinPanel}</>;

  if (active === 'Bank accounts') return <><SectionHeading eyebrow="MEMBER WORKSPACE" title="Bank accounts" description="Manage multiple dedicated accounts for receiving deposits into your thrift workspace." />{depositAccountsPanel}</>;

  if (active !== 'Overview') {
    const details: Record<string, { title: string; desc: string; icon: LucideIcon }> = {
      'My groups': { title: 'Your groups will appear here', desc: 'Once you join or are invited to a group, its membership, contribution schedule and rules will be shown here.', icon: Users },
      Contributions: { title: 'No contribution records loaded', desc: 'Confirmed and pending records will appear here when connected to your authenticated account data.', icon: Wallet },
      'Bank accounts': { title: 'Your deposit accounts', desc: 'View your dedicated deposit accounts and request another supported bank account.', icon: Landmark },
      Statements: { title: 'Statements will appear here', desc: 'Your available statements and receipts will be listed here after the statement API is connected.', icon: FileText },
      'Support & disputes': { title: 'Support centre', desc: 'Support requests and dispute tracking will be available here when their workflows are implemented.', icon: CircleHelp },
    };
    const d = details[active] ?? details['My groups'];
    return <><SectionHeading eyebrow="MEMBER WORKSPACE" title={active} description="A clear view of your thrift activity and account." /><EmptyPanel icon={d.icon} title={d.title} body={d.desc} /></>;
  }

  return <>
    <SectionHeading eyebrow="MEMBER WORKSPACE" title={profile ? `Welcome back, ${profile.name.split(' ')[0]}.` : 'Your savings, at a glance.'} description={profile ? `Signed in as ${profile.email}. Your dashboard uses authenticated account data.` : 'Welcome to your personal thrift workspace. Your confirmed activity will appear here.'} />
    <div className="notice"><div className="notice-symbol"><Activity size={18} /></div><div className="notice-copy"><strong>Account data connected securely</strong><p>Your profile, active thrift groups and contribution records are loaded from the authenticated API. Financial totals remain hidden until verified contribution records exist.</p></div><button className="text-button signout-button" onClick={signOut}><LogOut size={15} /> Sign out</button></div>
    {depositAccountsPanel}
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
