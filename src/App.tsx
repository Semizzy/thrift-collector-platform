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
  { label: 'Wallet', icon: Wallet },
  { label: 'Deposit & payout', icon: ArrowDownLeft },
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
  const [active, setActive] = useState(() => new URLSearchParams(window.location.search).get('section') || 'Overview');
  const [searchOpen, setSearchOpen] = useState(false);
  const [canReviewDeposits, setCanReviewDeposits] = useState(false);

  useEffect(() => {
    document.documentElement.dataset.theme = dark ? 'dark' : 'light';
  }, [dark]);

  useEffect(() => {
    const handlePop = () => {
      const nextPage = getPage();
      setPage(nextPage);
      setActive(new URLSearchParams(window.location.search).get('section') || 'Overview');
      setMenuOpen(false);
    };
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
          <div><strong>Semizzy<span> Thrift</span></strong><small>SEMIZZY THRIFT • GROUP SAVINGS</small></div>
          <button className="icon-button mobile-close" aria-label="Close menu" onClick={() => setMenuOpen(false)}><X size={20} /></button>
        </div>
        <div className="workspace-label">{page === 'admin' ? 'PLATFORM ADMIN' : 'YOUR WORKSPACE'}</div>
        <nav className="nav-list">
          {items.map(({ label, icon: Icon }) => (
            <button key={label} className={`nav-link ${active === label ? 'nav-active' : ''}`} onClick={() => { setActive(label); const url = new URL(window.location.href); url.searchParams.set('section', label); window.history.pushState({}, '', url.pathname + url.search + url.hash); setMenuOpen(false); }}>
              <Icon size={19} /><span>{label}</span>{label === 'Approvals & disputes' && page === 'admin' && <span className="nav-dot" />}
            </button>
          ))}
        </nav>
        <div className="sidebar-bottom">
          <div className="help-card"><div className="help-icon"><CircleHelp size={18} /></div><strong>Need a hand?</strong><p>Find guidance for using your workspace.</p><button onClick={() => setActive(page === 'admin' ? 'Settings' : 'Support & disputes')}>Open help <ArrowRight size={14} /></button></div>
          <div className="sidebar-profile"><div className="avatar">ST</div><div className="profile-copy"><strong>{page === 'admin' ? 'Platform operator' : 'Your account'}</strong><small>{page === 'admin' ? 'Protected read-only workspace' : 'Member workspace'}</small></div><button className="icon-button" aria-label="Account options"><ChevronDown size={17} /></button></div>
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
          <footer className="footer"><span>© {new Date().getFullYear()} Semizzy Thrift</span><span>Built for clarity, trust and accountability</span></footer>
        </div>
        <nav className="mobile-tabs" aria-label="Quick navigation">
          {(page === 'admin' ? adminNav.slice(0, 4) : userNav.slice(0, 4)).map(({ label, icon: Icon }) => (
            <button key={label} className={active === label ? 'tab-active' : ''} onClick={() => { setActive(label); const url = new URL(window.location.href); url.searchParams.set('section', label); window.history.pushState({}, '', url.pathname + url.search + url.hash); }}><Icon size={19} /><span>{label === 'Overview' ? 'Home' : label === 'Contributions' ? 'Payments' : label === 'Statements' ? 'Reports' : label === 'Collections' ? 'Collect' : label === 'Reconciliation' ? 'Reconcile' : label === 'My groups' ? 'Groups' : label === 'Members & businesses' ? 'Members' : label}</span></button>
          ))}
        </nav>
      </main>
    </div>
  );
}

function SectionHeading({ eyebrow, title, description }: { eyebrow: string; title: string; description: string }) {
  return <div className="section-heading"><div><div className="eyebrow">{eyebrow}</div><h1>{title}</h1><p>{description}</p></div></div>;
}

function EmptyPanel({ icon: Icon, title, body }: { icon: LucideIcon; title: string; body: string }) {
  return <div className="empty-panel"><div className="empty-icon"><Icon size={23} /></div><strong>{title}</strong><p>{body}</p></div>;
}

function PaymentHistoryRow({ item }: { item: PaymentRequest }) {
  const status = item.status.replaceAll('_', ' ');
  const reviewNote = item.review_reason || item.decision_reason;
  return <article className="payment-history-row">
    <div className="payment-history-main"><strong>{item.purpose || 'Payment request'}</strong><small>#{item.id} · {item.client_reference} · {new Date(item.created_at).toLocaleString()}</small>{reviewNote && <small>Review note: {reviewNote}</small>}</div>
    <div className="payment-history-side"><strong>{new Intl.NumberFormat('en-NG', { style: 'currency', currency: item.currency_code }).format(item.amount_minor / 100)}</strong><span className={'status-pill ' + (['rejected', 'failed'].includes(item.status) ? 'status-warning' : 'status-neutral')}>{status}</span></div>
  </article>;
}

type DashboardProfile = { id: number; name: string; email: string; email_verified_at: string | null };
type DepositAccount = { id: number; provider: string; bank_code: string; bank_name: string; account_number: string | null; account_name: string | null; currency_code: string; status: string; assigned_at: string | null };
type DepositBank = { code: string; name: string; provider: string; currency_code: string };
type WalletSummary = { currency_code: string; balance_minor: number; withdrawals_enabled_for_live_execution: boolean; message: string };
type WalletEntry = { id: number; direction: 'debit' | 'credit'; amount_minor: number; balance_after_minor: number; created_at: string; source_type: string; source_id: number; description: string; idempotency_key: string };
type PaymentRequest = { id: number; provider: string; method: string; status: string; amount_minor: number; currency_code: string; client_reference: string; provider_reference: string | null; purpose: string | null; review_reason?: string | null; decision_reason?: string | null; created_at: string; reviewed_at?: string | null; approved_at?: string | null; paid_confirmed_at?: string | null };
type PayoutBank = { code: string; name: string; country?: string; supports_account_name_resolution?: boolean };
type PendingAccountDiagnostic = { id: number; bank_name: string; bank_code: string; currency_code: string; status: string; created_at: string | null; age_minutes: number; recovery_attempts: number; last_recovery_at: string | null; last_recovery_result: string | null; has_provider_customer_reference: boolean; has_provider_account_reference: boolean; diagnostic_code: string; recommended_action: string; escalation: { id: number; status: string; created_at: string } | null };
type ProviderDeposit = { id: number; provider: string; provider_transaction_id: string; provider_reference: string | null; amount_minor: number; currency_code: string; status: string; occurred_at: string | null; created_at: string; user_id: number | null; account?: { bank_name?: string; account_name_ciphertext?: string | null } | null; safe_metadata?: { channel?: string; account_matched?: boolean } | null };
type DashboardSummary = { user: DashboardProfile; active_workspaces_count: number; workspaces: Array<{ id: number; name: string; country_code: string; status: string; role: string }>; active_groups_count: number; groups: Array<{ id: number; business_id: number; name: string; description: string | null; currency_code: string; status: string; role: string; joined_at: string | null }>; confirmed_contribution_totals: Array<{ currency_code: string; total_minor: number; records_count: number }>; recent_contributions: Array<{ id: number; thrift_group_id: number; user_id: number; amount_minor: number; currency_code: string; status: string; collection_method: string; reference: string | null; created_at: string; confirmed_at: string | null; group: { id: number; name: string; currency_code: string } | null }>; upcoming_obligations_count: number; upcoming_obligations: Array<{ id: number; status: string; expected_amount_minor: number; currency_code: string; due_on: string | null; group: { id: number; name: string } | null }>; financial_data_status: string; message: string };

function SecuritySettingsPanel() {
  const [hours, setHours] = useState('1');
  const [reason, setReason] = useState('');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  useEffect(() => {
    let cancelled = false;
    const token = sessionStorage.getItem('tcp_access_token') ?? '';
    if (!token) {
      setError('Sign in with an authorized platform administrator account.');
      setLoading(false);
      return () => { cancelled = true; };
    }
    fetch('/api/v1/admin/security-settings', {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    }).then(async response => {
      if (!response.ok) throw new Error(response.status === 403
        ? 'This account needs the platform.security.manage permission.'
        : 'Could not load security settings.');
      return response.json() as Promise<{ data: { login_lockout_hours: number } }>;
    }).then(result => {
      if (!cancelled) setHours(String(result.data.login_lockout_hours));
    }).catch(cause => {
      if (!cancelled) setError(cause instanceof Error ? cause.message : 'Could not load security settings.');
    }).finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, []);

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    setMessage('');
    setError('');
    const token = sessionStorage.getItem('tcp_access_token') ?? '';
    try {
      const response = await fetch('/api/v1/admin/security-settings', {
        method: 'PUT',
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({ login_lockout_hours: Number(hours), reason }),
      });
      const result = await response.json() as { message?: string; errors?: Record<string, string[]> };
      if (!response.ok) throw new Error(result.message ?? Object.values(result.errors ?? {}).flat()[0] ?? 'Could not save security settings.');
      setMessage(result.message ?? 'Security settings saved and audited.');
      setReason('');
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : 'Could not save security settings.');
    } finally {
      setSaving(false);
    }
  }

  return <section className="panel">
    <div className="panel-heading"><div><h2>Account security policy</h2><p>Admin-controlled lockout policy. Changes are recorded in the platform audit log.</p></div></div>
    {loading ? <p className="muted">Loading security settings…</p> : <>
      {error && <div className="inline-alert inline-alert-error" role="alert">{error}</div>}
      {message && <div className="inline-alert inline-alert-success" role="status">{message}</div>}
      <div className="security-policy-grid">
        <article className="security-policy-item"><strong>Failed password logins</strong><p>Account locks after 3 consecutive failures. A password reset clears the lock immediately.</p></article>
        <article className="security-policy-item"><strong>Transaction PIN failures</strong><p>Transactions lock after 3 incorrect PINs for 15 minutes.</p></article>
      </div>
      <form className="security-settings-form" onSubmit={save}>
        <label htmlFor="login-lockout-hours">Password login lockout duration (hours)</label>
        <input id="login-lockout-hours" type="number" min="1" max="720" required value={hours} onChange={event => setHours(event.target.value)} />
        <small>Allowed range: 1–720 hours. Existing active locks keep their current expiry.</small>
        <label htmlFor="security-change-reason">Reason for change</label>
        <textarea id="security-change-reason" required minLength={8} maxLength={500} value={reason} onChange={event => setReason(event.target.value)} placeholder="Explain why this security policy is changing." />
        <button className="button button-primary" type="submit" disabled={saving || !reason.trim() || Number(hours) < 1 || Number(hours) > 720}>{saving ? 'Saving…' : 'Save security policy'} <ArrowRight size={16} /></button>
      </form>
    </>}
  </section>;
}

function UserDashboard({ active, onReviewerAccessChange }: { active: string; onReviewerAccessChange: (allowed: boolean) => void }) {
  const [token, setToken] = useState(() => sessionStorage.getItem('tcp_access_token') ?? '');
  const [profile, setProfile] = useState<DashboardProfile | null>(null);
  const [dashboardData, setDashboardData] = useState<DashboardSummary | null>(null);
  const [authLoading, setAuthLoading] = useState(Boolean(token));
  const [authError, setAuthError] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [authMode, setAuthMode] = useState<'login' | 'register'>(() => window.location.pathname === '/register' ? 'register' : 'login');
  const [fullName, setFullName] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
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
  const [depositRequests, setDepositRequests] = useState<PaymentRequest[]>([]);
  const [payoutRequests, setPayoutRequests] = useState<PaymentRequest[]>([]);
  const [paymentBanks, setPaymentBanks] = useState<PayoutBank[]>([]);
  const [paymentLoading, setPaymentLoading] = useState(false);
  const [paymentRefreshKey, setPaymentRefreshKey] = useState(0);
  const [paymentSubmitting, setPaymentSubmitting] = useState(false);
  const [paymentError, setPaymentError] = useState('');
  const [paymentMessage, setPaymentMessage] = useState('');
  const [depositAmount, setDepositAmount] = useState('');
  const [depositPurpose, setDepositPurpose] = useState('');
  const [depositPin, setDepositPin] = useState('');
  const [depositReference, setDepositReference] = useState('');
  const [payoutAmount, setPayoutAmount] = useState('');
  const [payoutPurpose, setPayoutPurpose] = useState('');
  const [payoutPin, setPayoutPin] = useState('');
  const [payoutReference, setPayoutReference] = useState('');
  const [payoutBankCode, setPayoutBankCode] = useState('');
  const [payoutAccountNumber, setPayoutAccountNumber] = useState('');
  const [walletSummary, setWalletSummary] = useState<WalletSummary | null>(null);
  const [walletEntries, setWalletEntries] = useState<WalletEntry[]>([]);
  const [walletLoading, setWalletLoading] = useState(false);
  const [walletError, setWalletError] = useState('');
  const [walletRefreshKey, setWalletRefreshKey] = useState(0);
  const [groupName, setGroupName] = useState('');
  const [groupDescription, setGroupDescription] = useState('');
  const [groupCurrency, setGroupCurrency] = useState('NGN');
  const [groupCreating, setGroupCreating] = useState(false);
  const [groupFeedback, setGroupFeedback] = useState('');
  const [groupError, setGroupError] = useState('');
  const [adjustmentRecordId, setAdjustmentRecordId] = useState('');
  const [adjustmentType, setAdjustmentType] = useState<'correction' | 'reversal'>('correction');
  const [adjustmentAmount, setAdjustmentAmount] = useState('');
  const [adjustmentReason, setAdjustmentReason] = useState('');
  const [adjustmentSubmitting, setAdjustmentSubmitting] = useState(false);
  const [adjustmentFeedback, setAdjustmentFeedback] = useState('');
  const [adjustmentError, setAdjustmentError] = useState('');

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
    if (!token || !selectedBusinessId || !['Wallet', 'Overview', 'Deposit & payout'].includes(active)) return;
    let cancelled = false;
    setWalletLoading(true); setWalletError('');
    const country = dashboardData?.workspaces.find(workspace => String(workspace.id) === selectedBusinessId)?.country_code;
    const currency = country === 'GH' ? 'GHS' : 'NGN';
    const headers = { Authorization: 'Bearer ' + token, Accept: 'application/json', 'X-Business-Id': selectedBusinessId };
    Promise.all([
      fetch('/api/v1/business/wallet?currency_code=' + currency, { headers }).then(async response => { const result = await response.json(); if (!response.ok) throw new Error(result.message ?? 'Could not load wallet balance.'); return result.data as WalletSummary; }),
      fetch('/api/v1/business/wallet/ledger?currency_code=' + currency + '&limit=20', { headers }).then(async response => { const result = await response.json(); if (!response.ok) throw new Error(result.message ?? 'Could not load wallet ledger.'); return result.data as WalletEntry[]; }),
    ]).then(([summary, entries]) => { if (!cancelled) { setWalletSummary(summary); setWalletEntries(entries); } })
      .catch(error => { if (!cancelled) setWalletError(error instanceof Error ? error.message : 'Could not load wallet data.'); })
      .finally(() => { if (!cancelled) setWalletLoading(false); });
    return () => { cancelled = true; };
  }, [token, selectedBusinessId, active, walletRefreshKey, dashboardData]);

  useEffect(() => {
    if (!token || !selectedBusinessId || active !== 'Deposit & payout') return;
    let cancelled = false;
    setPaymentLoading(true); setPaymentError('');
    const headers = { Authorization: 'Bearer ' + token, Accept: 'application/json', 'X-Business-Id': selectedBusinessId };
    Promise.all([
      fetch('/api/v1/business/payments/deposits', { headers }).then(async response => { const result = await response.json(); if (!response.ok) throw new Error(result.message ?? 'Could not load deposit history.'); return result.data.data as PaymentRequest[]; }),
      fetch('/api/v1/business/payments/payouts', { headers }).then(async response => { const result = await response.json(); if (!response.ok) throw new Error(result.message ?? 'Could not load payout history.'); return result.data.data as PaymentRequest[]; }),
      fetch('/api/v1/business/payments/banks', { headers }).then(async response => { const result = await response.json(); if (!response.ok) return [] as PayoutBank[]; return result.data as PayoutBank[]; }).catch(() => [] as PayoutBank[]),
    ]).then(([deposits, payouts, banks]) => {
      if (!cancelled) { setDepositRequests(deposits); setPayoutRequests(payouts); setPaymentBanks(banks); if (!payoutBankCode && banks.length) setPayoutBankCode(banks[0].code); }
    }).catch(error => { if (!cancelled) setPaymentError(error instanceof Error ? error.message : 'Could not load payment requests.'); })
      .finally(() => { if (!cancelled) setPaymentLoading(false); });
    return () => { cancelled = true; };
  }, [token, selectedBusinessId, active, paymentRefreshKey]);

  function newClientReference(prefix: string) {
    return prefix + '-' + Date.now().toString(36).toUpperCase() + '-' + Math.random().toString(36).slice(2, 9).toUpperCase();
  }

  async function submitDepositRequest(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setPaymentError(''); setPaymentMessage('');
    const amount = Math.round(Number(depositAmount) * 100);
    if (!Number.isSafeInteger(amount) || amount < 1 || !depositPurpose.trim()) { setPaymentError('Enter a valid amount and explain the purpose.'); return; }
    const reference = depositReference.trim() || newClientReference('DEP');
    setPaymentSubmitting(true);
    try {
      const response = await fetch('/api/v1/business/payments/deposits', {
        method: 'POST', headers: { Authorization: 'Bearer ' + token, Accept: 'application/json', 'Content-Type': 'application/json', 'X-Business-Id': selectedBusinessId },
        body: JSON.stringify({ provider: 'manual', amount_minor: amount, currency_code: dashboardData?.workspaces.find(workspace => String(workspace.id) === selectedBusinessId)?.country_code === 'GH' ? 'GHS' : 'NGN', client_reference: reference, purpose: depositPurpose.trim(), transaction_pin: depositPin }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message ?? 'Deposit request could not be submitted.');
      setPaymentMessage(result.message ?? 'Deposit request submitted for review.'); setDepositPin(''); setDepositAmount(''); setDepositPurpose(''); setDepositReference('');
      setDepositRequests(current => [result.data as PaymentRequest, ...current.filter(item => item.id !== result.data.id)]);
    } catch (error) { setPaymentError(error instanceof Error ? error.message : 'Deposit request could not be submitted.'); }
    finally { setPaymentSubmitting(false); }
  }

  async function submitPayoutRequest(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setPaymentError(''); setPaymentMessage('');
    const amount = Math.round(Number(payoutAmount) * 100);
    if (!Number.isSafeInteger(amount) || amount < 1 || payoutPurpose.trim().length < 3 || !/^[0-9]{10}$/.test(payoutAccountNumber)) { setPaymentError('Enter a valid amount, purpose and 10-digit destination account number.'); return; }
    setPaymentSubmitting(true);
    try {
      const headers = { Authorization: 'Bearer ' + token, Accept: 'application/json', 'Content-Type': 'application/json', 'X-Business-Id': selectedBusinessId };
      const resolveResponse = await fetch('/api/v1/business/payments/accounts/resolve', { method: 'POST', headers, body: JSON.stringify({ bank_code: payoutBankCode, account_number: payoutAccountNumber }) });
      const resolveResult = await resolveResponse.json();
      if (!resolveResponse.ok) throw new Error(resolveResult.message ?? 'Destination account verification failed.');
      const reference = payoutReference.trim() || newClientReference('PAY');
      const response = await fetch('/api/v1/business/payments/payouts', {
        method: 'POST', headers,
        body: JSON.stringify({ provider: 'manual', amount_minor: amount, currency_code: dashboardData?.workspaces.find(workspace => String(workspace.id) === selectedBusinessId)?.country_code === 'GH' ? 'GHS' : 'NGN', client_reference: reference, purpose: payoutPurpose.trim(), transaction_pin: payoutPin, account_verification_id: resolveResult.data.verification_id }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message ?? 'Payout request could not be submitted.');
      setPaymentMessage(result.message ?? 'Payout request submitted for independent review.'); setPayoutPin(''); setPayoutAmount(''); setPayoutPurpose(''); setPayoutReference(''); setPayoutAccountNumber('');
      setPayoutRequests(current => [result.data as PaymentRequest, ...current.filter(item => item.id !== result.data.id)]);
    } catch (error) { setPaymentError(error instanceof Error ? error.message : 'Payout request could not be submitted.'); }
    finally { setPaymentSubmitting(false); }
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
    event.preventDefault(); setAuthError('');
    if (!email.trim() || !password) { setAuthError('Enter your email address and password.'); return; }
    setAuthLoading(true);
    try {
      const response = await fetch('/api/v1/auth/login', {
        method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ email: email.trim(), password }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message ?? result.errors?.email?.[0] ?? 'Sign in failed. Check your details and try again.');
      sessionStorage.setItem('tcp_access_token', result.access_token);
      setToken(result.access_token); setPassword('');
    } catch (error) {
      setAuthError(error instanceof Error ? error.message : 'Sign in failed.'); setAuthLoading(false);
    }
  }

  async function register(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setAuthError('');
    const normalizedName = fullName.trim();
    const normalizedEmail = email.trim();
    if (normalizedName.length < 2) { setAuthError('Enter your full name (at least 2 characters).'); return; }
    if (normalizedName.length > 120) { setAuthError('Your name must be 120 characters or fewer.'); return; }
    if (!/^[^\\s@]+@[^\\s@]+\\.[^\\s@]+$/.test(normalizedEmail)) { setAuthError('Enter a valid email address.'); return; }
    if (password.length < 12) { setAuthError('Use a password with at least 12 characters.'); return; }
    if (password.length > 200) { setAuthError('Your password must be 200 characters or fewer.'); return; }
    if (password !== passwordConfirmation) { setAuthError('Your passwords do not match.'); return; }
    setAuthLoading(true);
    setAuthLoading(true);
    try {
      const response = await fetch('/api/v1/auth/register', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ name: normalizedName, email: normalizedEmail, password, password_confirmation: passwordConfirmation }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message ?? result.errors?.name?.[0] ?? result.errors?.email?.[0] ?? result.errors?.password?.[0] ?? 'Could not create your account. Check the details and try again.');
      sessionStorage.setItem('tcp_access_token', result.access_token);
      setToken(result.access_token); setPassword(''); setPasswordConfirmation('');
    } catch (error) {
      setAuthError(error instanceof Error ? error.message : 'Could not create your account.'); setAuthLoading(false);
    }
  }

  async function submitContributionAdjustment(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const selectedRecord = dashboardData?.recent_contributions.find(record => String(record.id) === adjustmentRecordId);
    if (!token || !selectedBusinessId || !selectedRecord) { setAdjustmentError('Choose a contribution record from your account first.'); return; }
    setAdjustmentSubmitting(true); setAdjustmentFeedback(''); setAdjustmentError('');
    try {
      const payload: { adjustment_type: 'correction' | 'reversal'; reason: string; proposed_amount_minor?: number } = { adjustment_type: adjustmentType, reason: adjustmentReason.trim() };
      if (adjustmentType === 'correction') payload.proposed_amount_minor = Math.round(Number(adjustmentAmount) * 100);
      const response = await fetch(`/api/v1/business/groups/${selectedRecord.thrift_group_id}/contributions/${selectedRecord.id}/adjustments`, {
        method: 'POST',
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/json', 'Content-Type': 'application/json', 'X-Business-Id': selectedBusinessId },
        body: JSON.stringify(payload),
      });
      const result = await response.json() as { message?: string; errors?: Record<string, string[]> };
      if (!response.ok) throw new Error(result.message ?? Object.values(result.errors ?? {}).flat()[0] ?? 'Could not submit the adjustment request. Check your group permissions.');
      setAdjustmentFeedback(result.message ?? 'Adjustment request submitted for independent review.');
      setAdjustmentReason(''); setAdjustmentAmount('');
    } catch (cause) {
      setAdjustmentError(cause instanceof Error ? cause.message : 'Could not submit the adjustment request.');
    } finally { setAdjustmentSubmitting(false); }
  }

  async function createThriftGroup(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!token || !selectedBusinessId) { setGroupError('Choose an active business workspace before creating a group.'); return; }
    setGroupCreating(true); setGroupFeedback(''); setGroupError('');
    try {
      const response = await fetch('/api/v1/business/groups', {
        method: 'POST',
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/json', 'Content-Type': 'application/json', 'X-Business-Id': selectedBusinessId },
        body: JSON.stringify({ name: groupName.trim(), description: groupDescription.trim() || null, currency_code: groupCurrency }),
      });
      const result = await response.json() as { data?: { id?: number; name?: string }; message?: string; errors?: Record<string, string[]> };
      if (!response.ok) throw new Error(result.message ?? Object.values(result.errors ?? {}).flat()[0] ?? 'Could not create the thrift group. Check your workspace permissions.');
      const refreshed = await fetch('/api/v1/dashboard/summary', { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } });
      if (refreshed.ok) {
        const summary = await refreshed.json() as { data: DashboardSummary };
        setDashboardData(summary.data);
      }
      setGroupFeedback(result.message ?? `Thrift group${result.data?.name ? ` “${result.data.name}”` : ''} created successfully.`);
      setGroupName(''); setGroupDescription('');
    } catch (cause) {
      setGroupError(cause instanceof Error ? cause.message : 'Could not create the thrift group.');
    } finally { setGroupCreating(false); }
  }

  async function signOut() {
    if (token) {
      try { await fetch('/api/v1/auth/logout', { method: 'POST', headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } }); } catch { /* Clear local session even if the network is unavailable. */ }
    }
    sessionStorage.removeItem('tcp_access_token'); setToken(''); setProfile(null); setDashboardData(null);
  }

  if (!token) return <section className={`auth-gate ${authMode === 'register' ? 'auth-gate-register' : 'auth-gate-login'}`}>
    <div className="auth-showcase">
      <div className="auth-showcase-brand"><span className="brand-mark"><Wallet size={22} /></span><span>SEMIZZY <strong>THRIFT</strong></span></div>
      {authMode === 'register' ? <>
        <div className="eyebrow">YOUR NEXT CHAPTER STARTS HERE</div>
        <h2>Build better saving habits, <em>together.</em></h2>
        <p>Create your member account to join savings groups, follow contributions and keep your financial activity in one place.</p>
        <ul className="auth-benefits"><li><span>01</span><div><strong>Stay organised</strong><small>Keep track of your groups and contributions.</small></div></li><li><span>02</span><div><strong>Know where you stand</strong><small>See account activity and payment records.</small></div></li><li><span>03</span><div><strong>Security comes first</strong><small>Use your own email and a strong password.</small></div></li></ul>
      </> : <>
        <div className="eyebrow">A CLEARER WAY TO SAVE</div>
        <h2>Good to have you <em>back.</em></h2>
        <p>Sign in to continue to your Semizzy Thrift workspace and review your savings activity.</p>
        <div className="auth-trust-note"><ShieldCheck size={20} /><span><strong>Your account, your access.</strong><small>Never share your password or verification codes.</small></span></div>
      </>}
    </div>
    <div className="auth-card">
      <div className="eyebrow">{authMode === 'register' ? 'MEMBER REGISTRATION' : 'MEMBER ACCESS'}</div>
      <h1>{authMode === 'register' ? 'Create your account' : 'Sign in to your account'}</h1>
      <p>{authMode === 'register' ? 'Enter your details below to get started.' : 'Welcome back. Enter your details to sign in.'}</p>
      <form className="auth-form" onSubmit={authMode === 'register' ? register : signIn}>
        {authMode === 'register' && <><label htmlFor="member-name">Full name</label><input id="member-name" type="text" autoComplete="name" minLength={2} maxLength={120} required value={fullName} onChange={event => setFullName(event.target.value)} /></>}
        <label htmlFor="member-email">Email address</label><input id="member-email" type="email" autoComplete="email" maxLength={254} required value={email} onChange={event => setEmail(event.target.value)} />
        <label htmlFor="member-password">Password</label><input id="member-password" type="password" autoComplete={authMode === 'register' ? 'new-password' : 'current-password'} minLength={authMode === 'register' ? 12 : undefined} maxLength={200} required value={password} onChange={event => setPassword(event.target.value)} />
        {authMode === 'register' && <><label htmlFor="member-password-confirmation">Confirm password</label><input id="member-password-confirmation" type="password" autoComplete="new-password" minLength={12} maxLength={200} required value={passwordConfirmation} onChange={event => setPasswordConfirmation(event.target.value)} /></>}
        {authError && <p className="auth-error" role="alert">{authError}</p>}
        <button className="button button-primary auth-submit" type="submit" disabled={authLoading}>{authLoading ? (authMode === 'register' ? 'Creating account…' : 'Signing in…') : (authMode === 'register' ? 'Create my account' : 'Sign in securely')} <ArrowRight size={16} /></button>
      </form>
      <p className="auth-footnote">{authMode === 'register' ? 'We will send an email verification link. Use an email address you can access.' : 'Your account details are loaded securely after sign-in.'}</p>
      <p className="auth-switch-prompt">{authMode === 'register' ? 'Already registered?' : 'New to Semizzy Thrift?'} <button type="button" className="text-button" onClick={() => { const nextMode = authMode === 'login' ? 'register' : 'login'; window.history.pushState({}, '', nextMode === 'register' ? '/register' : '/login'); setAuthMode(nextMode); setAuthError(''); setPassword(''); setPasswordConfirmation(''); setFullName(''); setEmail(''); }}>{authMode === 'register' ? 'Sign in instead' : 'Create an account'}</button></p>
    </div>
  </section>;
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

  const walletPanel = <section className="panel wallet-panel">
    <div className="panel-heading"><div><h2>My savings wallet</h2><p>Confirmed cash contributions and independently reconciled deposits, less withdrawal requests.</p></div><button className="text-button" onClick={() => setWalletRefreshKey(value => value + 1)}><RefreshCw size={15} /> Refresh</button></div>
    {walletError && <p className="auth-error" role="alert">{walletError}</p>}
    <div className="wallet-balance-card"><span>Available wallet balance</span><strong>{walletSummary ? new Intl.NumberFormat('en-NG', { style: 'currency', currency: walletSummary.currency_code }).format(walletSummary.balance_minor / 100) : '—'}</strong><small>{walletSummary?.currency_code ?? 'NGN'} · amounts shown in major currency units</small></div>
    {walletLoading ? <p className="muted">Loading wallet ledger…</p> : walletEntries.length ? <div className="wallet-ledger-list">{walletEntries.map(entry => <article className="wallet-ledger-row" key={entry.id}><span className={'wallet-ledger-icon ' + entry.direction}>{entry.direction === 'credit' ? <ArrowDownLeft size={17} /> : <ArrowRight size={17} />}</span><div className="wallet-ledger-copy"><strong>{entry.description}</strong><small>{entry.source_type.replaceAll('_', ' ')} #{entry.source_id} · {new Date(entry.created_at).toLocaleString()}</small></div><div className="wallet-ledger-amount"><strong className={entry.direction === 'credit' ? 'wallet-credit' : 'wallet-debit'}>{entry.direction === 'credit' ? '+' : '−'}{new Intl.NumberFormat('en-NG', { style: 'currency', currency: walletSummary?.currency_code ?? 'NGN' }).format(entry.amount_minor / 100)}</strong><small>Balance {new Intl.NumberFormat('en-NG', { style: 'currency', currency: walletSummary?.currency_code ?? 'NGN' }).format(entry.balance_after_minor / 100)}</small></div></article>)}</div> : <div className="deposit-empty"><Wallet size={22} /><strong>No wallet entries yet</strong><p>Cash contributions credited after independent confirmation and non-cash savings matched during reconciliation will appear here.</p></div>}
    <p className="deposit-consent-note">Every wallet movement is double-entry and idempotent. A withdrawal is deducted when requested; rejected or confirmed failed withdrawals are refunded once. Live bank transfers remain disabled until release gates are approved.</p>
  </section>;

  const paymentRequestsPanel = <div className="payment-workflow">
    <section className="panel">
      <div className="panel-heading"><div><h2>Request a deposit</h2><p>Record a manual deposit request for independent review. A request alone does not credit your wallet; savings are credited after the contribution is confirmed or the deposit is reconciled.</p></div><ArrowDownLeft size={22} /></div>
      <form className="pin-form" onSubmit={submitDepositRequest}>
        <label htmlFor="deposit-amount">Amount ({dashboardData?.workspaces.find(workspace => String(workspace.id) === selectedBusinessId)?.country_code === 'GH' ? 'GHS' : 'NGN'})</label><input id="deposit-amount" type="number" min="1" step="0.01" required value={depositAmount} onChange={event => setDepositAmount(event.target.value)} />
        <label htmlFor="deposit-purpose">Purpose of deposit</label><input id="deposit-purpose" minLength={3} maxLength={255} required value={depositPurpose} onChange={event => setDepositPurpose(event.target.value)} placeholder="e.g. October thrift contribution" />
        <label htmlFor="deposit-reference">Request reference (optional)</label><input id="deposit-reference" maxLength={100} value={depositReference} onChange={event => setDepositReference(event.target.value)} placeholder="Auto-generated if blank" />
        <label htmlFor="deposit-transaction-pin">Transaction PIN</label><input id="deposit-transaction-pin" type="password" inputMode="numeric" pattern="[0-9]{4}" maxLength={4} autoComplete="off" required value={depositPin} onChange={event => setDepositPin(event.target.value.replace(/\D/g, '').slice(0, 4))} />
        <button className="button button-primary" type="submit" disabled={paymentSubmitting || !selectedBusinessId}>{paymentSubmitting ? 'Submitting…' : 'Submit deposit request'}</button>
      </form>
    </section>
    <section className="panel">
      <div className="panel-heading"><div><h2>Request a payout</h2><p>Verify a bank account and submit a withdrawal request. The amount is deducted from your wallet immediately and held while the request is reviewed; this screen does not execute a bank transfer.</p></div><ArrowRight size={22} /></div>
      {!paymentBanks.length && <p className="deposit-consent-note">The bank directory is unavailable. Payout requests require administrator-configured account verification credentials; deposit requests and history remain available.</p>}
      <form className="pin-form" onSubmit={submitPayoutRequest}>
        <label htmlFor="payout-amount">Amount ({dashboardData?.workspaces.find(workspace => String(workspace.id) === selectedBusinessId)?.country_code === 'GH' ? 'GHS' : 'NGN'})</label><input id="payout-amount" type="number" min="1" step="0.01" required value={payoutAmount} onChange={event => setPayoutAmount(event.target.value)} />
        <label htmlFor="payout-purpose">Purpose of payout</label><input id="payout-purpose" minLength={3} maxLength={255} required value={payoutPurpose} onChange={event => setPayoutPurpose(event.target.value)} placeholder="e.g. approved savings withdrawal" />
        <label htmlFor="payout-bank">Destination bank</label><select id="payout-bank" required value={payoutBankCode} onChange={event => setPayoutBankCode(event.target.value)}>{paymentBanks.map(bank => <option key={bank.code} value={bank.code}>{bank.name}</option>)}</select>
        <label htmlFor="payout-account-number">Destination account number</label><input id="payout-account-number" inputMode="numeric" pattern="[0-9]{10}" maxLength={10} required value={payoutAccountNumber} onChange={event => setPayoutAccountNumber(event.target.value.replace(/\D/g, '').slice(0, 10))} />
        <label htmlFor="payout-reference">Request reference (optional)</label><input id="payout-reference" maxLength={100} value={payoutReference} onChange={event => setPayoutReference(event.target.value)} placeholder="Auto-generated if blank" />
        <label htmlFor="payout-transaction-pin">Transaction PIN</label><input id="payout-transaction-pin" type="password" inputMode="numeric" pattern="[0-9]{4}" maxLength={4} autoComplete="off" required value={payoutPin} onChange={event => setPayoutPin(event.target.value.replace(/\D/g, '').slice(0, 4))} />
        <button className="button button-primary" type="submit" disabled={paymentSubmitting || !selectedBusinessId || !paymentBanks.length}>{paymentSubmitting ? 'Verifying and submitting…' : 'Verify bank & request payout'}</button>
      </form>
    </section>
    {paymentError && <p className="auth-error" role="alert">{paymentError}</p>}
    {paymentMessage && <p className="account-success" role="status">{paymentMessage}</p>}
    <section className="panel"><div className="panel-heading"><div><h2>Deposit request history</h2><p>Evidence review is separate from balance crediting.</p></div><button className="text-button" onClick={() => setPaymentRefreshKey(value => value + 1)}><RefreshCw size={14} /> Refresh</button></div>
      {paymentLoading ? <p className="muted">Loading request history…</p> : depositRequests.length ? <div className="payment-history">{depositRequests.map(item => <PaymentHistoryRow key={'deposit-'+item.id} item={item} />)}</div> : <EmptyPanel icon={ArrowDownLeft} title="No deposit requests" body="Your submitted deposit requests will appear here." />}
    </section>
    <section className="panel"><div className="panel-heading"><div><h2>Payout request history</h2><p>Approval status is not proof that a transfer was made.</p></div></div>
      {paymentLoading ? <p className="muted">Loading request history…</p> : payoutRequests.length ? <div className="payment-history">{payoutRequests.map(item => <PaymentHistoryRow key={'payout-'+item.id} item={item} />)}</div> : <EmptyPanel icon={ArrowRight} title="No payout requests" body="Your submitted payout requests will appear here." />}
    </section>
    <p className="deposit-consent-note">Wallet debits and credits are recorded in the immutable ledger. Provider execution remains disabled: approval is not proof of transfer, and ambiguous transfer outcomes stay debited until reconciled.</p>
  </div>;

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

  if (active === 'My groups' && dashboardData) return <><SectionHeading eyebrow="MEMBER WORKSPACE" title="My groups" description="View your active thrift groups or create a group in a workspace where you have arrangement-management permission." />
    <section className="panel"><div className="panel-heading"><div><h2>Create a thrift group</h2><p>Creates a group record and makes you its organizer. It does not start collections or move money.</p></div></div>
      {!dashboardData.workspaces.length ? <EmptyPanel icon={Users} title="Create a workspace first" body="A business workspace is required before a thrift group can be created." /> : <form className="pin-form" onSubmit={createThriftGroup}>
        <label htmlFor="new-group-workspace">Workspace</label><select id="new-group-workspace" required value={selectedBusinessId} onChange={event => setSelectedBusinessId(event.target.value)}>{dashboardData.workspaces.map(workspace => <option key={workspace.id} value={String(workspace.id)}>{workspace.name} · {workspace.country_code} · {workspace.role}</option>)}</select>
        <label htmlFor="new-group-name">Group name</label><input id="new-group-name" required minLength={2} maxLength={160} value={groupName} onChange={event => setGroupName(event.target.value)} placeholder="e.g. Monthly family savings" />
        <label htmlFor="new-group-description">Description (optional)</label><textarea id="new-group-description" maxLength={2000} value={groupDescription} onChange={event => setGroupDescription(event.target.value)} placeholder="Purpose, rules, or contribution expectations" />
        <label htmlFor="new-group-currency">Currency</label><select id="new-group-currency" value={groupCurrency} onChange={event => setGroupCurrency(event.target.value)}><option value="NGN">NGN · Nigerian naira</option><option value="GHS">GHS · Ghanaian cedi</option></select>
        {groupError && <p className="auth-error" role="alert">{groupError}</p>}{groupFeedback && <p className="account-success" role="status">{groupFeedback}</p>}
        <button className="button button-primary" type="submit" disabled={groupCreating || !selectedBusinessId || !groupName.trim()}>{groupCreating ? 'Creating group…' : 'Create thrift group'}</button>
      </form>}
    </section>
    {dashboardData.groups.length ? <div className="content-grid">{dashboardData.groups.map(group => <section className="panel" key={group.id}><div className="panel-heading"><div><h2>{group.name}</h2><p>{group.description || 'No group description provided.'}</p></div><span className="status-pill status-neutral">{group.status}</span></div><div className="stat-foot muted">{group.currency_code} · {group.role} · Group #{group.id}</div></section>)}</div> : <EmptyPanel icon={Users} title="No active thrift groups yet" body="Create a group above or accept an invitation to join one." />}</>;
  if (active === 'Contributions' && dashboardData) return <><SectionHeading eyebrow="MEMBER WORKSPACE" title="Contributions" description="Your contribution records from groups you belong to." />{dashboardData.recent_contributions.length ? <section className="panel"><div className="panel-heading"><div><h2>Recent contribution records</h2><p>Record confirmation is not proof of bank deposit or provider settlement.</p></div></div><div className="contribution-list">{dashboardData.recent_contributions.map(record => <div className="check-row" key={record.id}><span className="check-number">{record.status === 'confirmed' ? <CheckCircle2 size={17} /> : <CalendarClock size={17} />}</span><div><strong>{record.group?.name ?? `Group #${record.thrift_group_id}`}</strong><p>{new Date(record.created_at).toLocaleDateString()} · {record.collection_method.replace('_', ' ')}</p></div><div className="contribution-amount"><strong>{new Intl.NumberFormat('en-NG', { style: 'currency', currency: record.currency_code }).format(record.amount_minor / 100)}</strong><small>{record.status}</small></div></div>)}</div></section> : <EmptyPanel icon={Wallet} title="No contribution records yet" body="Records will appear here when a permitted organizer or collector records a contribution for you." />}</>;

  if (active === 'Statements') return <><SectionHeading eyebrow="MEMBER WORKSPACE · ACCOUNT RECORDS" title="Statements & activity" description="Review records returned by your authenticated contribution, wallet, deposit, and payout APIs. These records are not bank settlement certificates." />
    <section className="panel"><div className="panel-heading"><div><h2>Contribution statement</h2><p>Recent contribution records across your groups.</p></div></div>
      {dashboardData?.recent_contributions.length ? <div className="contribution-list">{dashboardData.recent_contributions.map(record => <div className="check-row" key={'statement-contribution-'+record.id}><span className="check-number">{record.status === 'confirmed' ? <CheckCircle2 size={17} /> : <CalendarClock size={17} />}</span><div><strong>{record.group?.name ?? `Group #${record.thrift_group_id}`}</strong><p>{new Date(record.created_at).toLocaleString()} · {record.collection_method.replaceAll('_', ' ')} · {record.status}</p></div><div className="contribution-amount"><strong>{new Intl.NumberFormat('en-NG', { style: 'currency', currency: record.currency_code }).format(record.amount_minor / 100)}</strong></div></div>)}</div> : <EmptyPanel icon={FileText} title="No contribution records" body="There are no contribution records available for this account yet." />}</section>
    {walletPanel}
    <section className="panel"><div className="panel-heading"><div><h2>Deposit and payout requests</h2><p>Request status from the payment workflow API.</p></div><button className="text-button" onClick={() => setPaymentRefreshKey(value => value + 1)}><RefreshCw size={14} /> Refresh</button></div>
      {paymentLoading ? <p className="muted">Loading payment requests…</p> : <><h3>Deposits</h3>{depositRequests.length ? <div className="payment-history">{depositRequests.map(item => <PaymentHistoryRow key={'statement-deposit-'+item.id} item={item} />)}</div> : <p className="muted">No deposit requests.</p>}<h3>Payouts</h3>{payoutRequests.length ? <div className="payment-history">{payoutRequests.map(item => <PaymentHistoryRow key={'statement-payout-'+item.id} item={item} />)}</div> : <p className="muted">No payout requests.</p>}</>}
    </section></>;

  if (active === 'Support & disputes') return <><SectionHeading eyebrow="MEMBER WORKSPACE · CONTRIBUTION CORRECTIONS" title="Support & disputes" description="Submit an auditable correction or reversal request for a contribution record. Requests do not change the original record or move money automatically." />
    <section className="panel"><div className="panel-heading"><div><h2>Request a contribution review</h2><p>Another authorized group member must review the request; you cannot approve your own request.</p></div><CircleHelp size={22} /></div>
      {!dashboardData?.recent_contributions.length ? <EmptyPanel icon={FileText} title="No contribution records available" body="A review request can be made after a contribution record appears in your account." /> : <form className="pin-form" onSubmit={submitContributionAdjustment}>
        <label htmlFor="adjustment-record">Contribution record</label><select id="adjustment-record" required value={adjustmentRecordId} onChange={event => setAdjustmentRecordId(event.target.value)}><option value="">Choose a contribution record</option>{dashboardData.recent_contributions.map(record => <option key={record.id} value={String(record.id)}>#{record.id} · {record.group?.name ?? `Group #${record.thrift_group_id}`} · {new Intl.NumberFormat('en-NG', { style: 'currency', currency: record.currency_code }).format(record.amount_minor / 100)} · {record.status}</option>)}</select>
        <label htmlFor="adjustment-type">Request type</label><select id="adjustment-type" value={adjustmentType} onChange={event => setAdjustmentType(event.target.value as 'correction' | 'reversal')}><option value="correction">Correction · propose a replacement amount</option><option value="reversal">Reversal · request that this record be reversed</option></select>
        {adjustmentType === 'correction' && <><label htmlFor="adjustment-amount">Proposed corrected amount</label><input id="adjustment-amount" type="number" min="0.01" step="0.01" required value={adjustmentAmount} onChange={event => setAdjustmentAmount(event.target.value)} /></>}
        <label htmlFor="adjustment-reason">Reason for review</label><textarea id="adjustment-reason" required minLength={8} maxLength={500} value={adjustmentReason} onChange={event => setAdjustmentReason(event.target.value)} placeholder="Explain the discrepancy and the evidence available." />
        {adjustmentError && <p className="auth-error" role="alert">{adjustmentError}</p>}{adjustmentFeedback && <p className="account-success" role="status">{adjustmentFeedback}</p>}
        <button className="button button-primary" type="submit" disabled={adjustmentSubmitting || !adjustmentRecordId || !adjustmentReason.trim() || (adjustmentType === 'correction' && (!adjustmentAmount || Number(adjustmentAmount) <= 0))}>{adjustmentSubmitting ? 'Submitting request…' : 'Submit for independent review'}</button>
      </form>}
    </section><section className="panel security-note"><div className="assurance-icon"><ShieldCheck size={21} /></div><div><strong>Original records are protected</strong><p>The API records the request and audit event. It does not silently overwrite the original contribution or post a financial adjustment.</p></div></section></>;

  if (active === 'Deposit review') return <><SectionHeading eyebrow="BUSINESS WORKSPACE · AUTHORIZED REVIEWERS" title="Deposit review" description="Independently review provider notifications and record an auditable evidence decision." />{providerDepositReviewPanel}</>;

  if (active === 'Account operations') return <><SectionHeading eyebrow="BUSINESS WORKSPACE · AUTHORIZED OPERATIONS" title="Pending account operations" description="Diagnose repeated or long-running provider assignments and escalate them for support follow-up." />{accountOperationsPanel}</>;

  if (active === 'Wallet') return <><SectionHeading eyebrow="MEMBER WORKSPACE · SAVINGS BALANCE" title="My wallet" description="See available savings, wallet credits and withdrawal deductions with a traceable ledger." />{walletPanel}</>;

  if (active === 'Deposit & payout') return <><SectionHeading eyebrow="MEMBER WORKSPACE · PAYMENT REQUESTS" title="Deposits & withdrawals" description="Submit requests with a purpose and transaction PIN, and follow the wallet and review status." />{walletPanel}{paymentRequestsPanel}</>;

  if (active === 'Security & PIN') return <><SectionHeading eyebrow="ACCOUNT SECURITY" title="Security & transaction PIN" description="Set or update the PIN used to confirm payment requests." />{transactionPinPanel}</>;

  if (active === 'Bank accounts') return <><SectionHeading eyebrow="MEMBER WORKSPACE" title="Bank accounts" description="Manage multiple dedicated accounts for receiving deposits into your thrift workspace." />{depositAccountsPanel}</>;

  if (active !== 'Overview') {
    const details: Record<string, { title: string; desc: string; icon: LucideIcon }> = {
      'My groups': { title: 'Your groups will appear here', desc: 'Once you join or are invited to a group, its membership, contribution schedule and rules will be shown here.', icon: Users },
      Contributions: { title: 'No contribution records loaded', desc: 'Confirmed and pending records will appear here when connected to your authenticated account data.', icon: Wallet },
      'Bank accounts': { title: 'Your deposit accounts', desc: 'View your dedicated deposit accounts and request another supported bank account.', icon: Landmark },
      Statements: { title: 'Statements will appear here', desc: 'Your available statements and receipts will be listed here after the statement API is connected.', icon: FileText },
      'Support & disputes': { title: 'Support API not yet available', desc: 'The current API exposes contribution-adjustment requests and business audit records, but no dedicated support-ticket or dispute-case endpoint. No fake ticket submission is shown here.', icon: CircleHelp },
    };
    const d = details[active] ?? details['My groups'];
    return <><SectionHeading eyebrow="MEMBER WORKSPACE" title={active} description="A clear view of your thrift activity and account." /><EmptyPanel icon={d.icon} title={d.title} body={d.desc} /></>;
  }

  return <>
    <SectionHeading eyebrow="MEMBER WORKSPACE" title={profile ? `Welcome back, ${profile.name.split(' ')[0]}.` : 'Your savings, at a glance.'} description={profile ? `Signed in as ${profile.email}. Your dashboard uses authenticated account data.` : 'Welcome to your personal thrift workspace. Your confirmed activity will appear here.'} />
    <div className="notice"><div className="notice-symbol"><Activity size={18} /></div><div className="notice-copy"><strong>Account data connected securely</strong><p>Your profile, active thrift groups and contribution records are loaded from the authenticated API. Financial totals remain hidden until verified contribution records exist.</p></div><button className="text-button signout-button" onClick={signOut}><LogOut size={15} /> Sign out</button></div>
    {walletPanel}
    {depositAccountsPanel}
    <section className="stats-grid" aria-label="Account summary">
      <article className="stat-card stat-featured"><div className="stat-top"><span>Total confirmed contributions</span><span className="stat-icon"><Wallet size={19} /></span></div><div className="stat-value">{dashboardData?.confirmed_contribution_totals.length ? dashboardData.confirmed_contribution_totals.map(total => `${total.currency_code} ${new Intl.NumberFormat('en-NG', { maximumFractionDigits: 2 }).format(total.total_minor / 100)}`).join(' · ') : '—'}</div><div className="stat-foot"><span className="status-pill status-neutral"><Activity size={13} /> Confirmed records only</span></div></article>
      <article className="stat-card"><div className="stat-top"><span>Active thrift groups</span><span className="stat-icon green"><Users size={19} /></span></div><div className="stat-value">{dashboardData?.active_groups_count ?? "—"}</div><div className="stat-foot muted">Groups where you have active membership</div></article>
      <article className="stat-card"><div className="stat-top"><span>Upcoming contributions</span><span className="stat-icon amber"><CalendarClock size={19} /></span></div><div className="stat-value">{dashboardData?.upcoming_obligations_count ?? '—'}</div><div className="stat-foot muted">Scheduled member obligations only</div></article>
      <article className="stat-card"><div className="stat-top"><span>Confirmed records</span><span className="stat-icon green"><CheckCircle2 size={19} /></span></div><div className="stat-value">{dashboardData?.confirmed_contribution_totals.reduce((sum, total) => sum + total.records_count, 0) ?? '—'}</div><div className="stat-foot muted">Confirmed records; not settlement proof</div></article>
    </section>
    <div className="content-grid">
      <section className="panel activity-panel"><div className="panel-heading"><div><h2>Recent activity</h2><p>Your latest contribution and account events</p></div><button className="text-button" onClick={() => { const url = new URL(window.location.href); url.searchParams.set('section', 'Statements'); window.history.pushState({}, '', url.pathname + url.search + url.hash); window.dispatchEvent(new PopStateEvent('popstate')); }}>View history <ArrowRight size={15} /></button></div>{dashboardData?.recent_contributions.length ? <div className="contribution-list">{dashboardData.recent_contributions.slice(0, 5).map(record => <div className="check-row" key={record.id}><span className="check-number">{record.status === 'confirmed' ? <CheckCircle2 size={17} /> : <CalendarClock size={17} />}</span><div><strong>{record.group?.name ?? `Group #${record.thrift_group_id}`}</strong><p>{new Date(record.created_at).toLocaleDateString()} · {record.status}</p></div><div className="contribution-amount"><strong>{new Intl.NumberFormat('en-NG', { style: 'currency', currency: record.currency_code }).format(record.amount_minor / 100)}</strong></div></div>)}</div> : <EmptyPanel icon={ArrowDownLeft} title="No contribution activity yet" body="Your contribution records will appear here when they exist in your groups." />}</section>
      <section className="panel next-panel"><div className="panel-heading"><div><h2>Upcoming contributions</h2><p>Scheduled due dates from your active groups</p></div></div>{dashboardData?.upcoming_obligations.length ? <div className="contribution-list">{dashboardData.upcoming_obligations.slice(0, 5).map(item => <div className="check-row" key={item.id}><span className="check-number"><CalendarClock size={17} /></span><div><strong>{item.group?.name ?? 'Thrift group'}</strong><p>Due {item.due_on ? new Date(`${item.due_on}T00:00:00`).toLocaleDateString() : 'date not set'}</p></div><div className="contribution-amount"><strong>{new Intl.NumberFormat('en-NG', { style: 'currency', currency: item.currency_code }).format(item.expected_amount_minor / 100)}</strong><small>Scheduled</small></div></div>)}</div> : <EmptyPanel icon={CalendarClock} title="No upcoming schedule loaded" body="Upcoming items appear after your organizer creates a schedule and generates due-date cycles. These entries do not initiate collections." />}</section>
    </div>
    <section className="panel assurance"><div className="assurance-icon"><ShieldCheck size={21} /></div><div><strong>Clear records. Accountable collections.</strong><p>Financial entries will clearly distinguish recorded collections, confirmed deposits and reconciled transactions.</p></div></section>
  </>;
}

type AdminAuditEvent = { id: number; action: string; actor?: { id: number; name: string; email: string } | null; subject?: { id: number; name: string; email: string } | null; metadata?: Record<string, unknown> | null; created_at: string };

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
  const [auditEvents, setAuditEvents] = useState<AdminAuditEvent[]>([]);
  const [auditLoading, setAuditLoading] = useState(false);
  const [auditError, setAuditError] = useState('');

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

  useEffect(() => {
    if (active !== 'Reports & audit') return;
    let cancelled = false;
    const token = sessionStorage.getItem('tcp_access_token') ?? '';
    if (!token) { setAuditEvents([]); setAuditError('Sign in with an authorized platform-admin account to view audit events.'); return () => { cancelled = true; }; }
    setAuditLoading(true); setAuditError('');
    fetch('/api/v1/admin/audit-events', { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } })
      .then(async response => {
        const result = await response.json() as { data?: { data?: AdminAuditEvent[] }; message?: string };
        if (!response.ok) throw new Error(response.status === 403 ? 'This account needs the platform.audit.view permission.' : result.message ?? 'Could not load platform audit events.');
        return result;
      })
      .then(result => { if (!cancelled) setAuditEvents(result.data?.data ?? []); })
      .catch(cause => { if (!cancelled) { setAuditEvents([]); setAuditError(cause instanceof Error ? cause.message : 'Could not load platform audit events.'); } })
      .finally(() => { if (!cancelled) setAuditLoading(false); });
    return () => { cancelled = true; };
  }, [active]);

  if (active === 'Reports & audit') return <>
    <SectionHeading eyebrow="PLATFORM ADMINISTRATION" title="Reports & audit" description="Audit events retrieved from the dedicated permission-protected platform audit endpoint." />
    {auditLoading ? <section className="panel"><EmptyPanel icon={Activity} title="Loading audit events" body="Checking platform.audit.view permission and retrieving the latest events." /></section>
      : auditError ? <section className="panel"><EmptyPanel icon={ShieldCheck} title="Audit records unavailable" body={auditError} /></section>
      : <section className="panel"><div className="panel-heading"><div><h2>Platform audit trail</h2><p>Latest 50 events, newest first. Sensitive metadata is not expanded by default.</p></div><button className="text-button" onClick={() => { setAuditEvents([]); setAuditError(''); setAuditLoading(true); const token = sessionStorage.getItem('tcp_access_token') ?? ''; fetch('/api/v1/admin/audit-events', { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } }).then(async response => { const result = await response.json() as { data?: { data?: AdminAuditEvent[] }; message?: string }; if (!response.ok) throw new Error(result.message ?? 'Could not refresh audit events.'); setAuditEvents(result.data?.data ?? []); }).catch(cause => setAuditError(cause instanceof Error ? cause.message : 'Could not refresh audit events.')).finally(() => setAuditLoading(false)); }}><RefreshCw size={14} /> Refresh</button></div>
        {auditEvents.length ? <div className="contribution-list">{auditEvents.map(event => <div className="check-row" key={event.id}><span className="check-number"><ShieldCheck size={17} /></span><div><strong>{event.action.replaceAll('.', ' ')}</strong><p>{event.actor?.name ?? 'System/unknown actor'}{event.subject?.name ? ` · Subject: ${event.subject.name}` : ''}</p></div><div className="contribution-amount"><small>{new Date(event.created_at).toLocaleString()}</small></div></div>)}</div> : <EmptyPanel icon={Activity} title="No audit events returned" body="No events are available for this query, or the platform has not recorded any yet." />}
      </section>}
  </>;

  if (!['Overview', 'Settings'].includes(active)) return <><SectionHeading eyebrow="PLATFORM ADMINISTRATION" title={active} description="This area is available only when a matching protected platform-admin API workflow exists." /><section className="panel"><EmptyPanel icon={ShieldCheck} title={`${active} workflow is not exposed by the current platform-admin API`} body="The current API supports protected operational metrics, audit events, and security-policy settings. It does not yet expose platform-wide member management, collections, reconciliation, or approval endpoints. No mock controls or fabricated records are shown here." /></section></>;

  return <>
    <SectionHeading eyebrow="PLATFORM ADMINISTRATION" title={active === 'Overview' ? 'Operations overview.' : active} description="Protected, read-only operational data from the authenticated platform API." />
    <div className="preview-warning"><ShieldCheck size={19} /><div><strong>Protected administration</strong><p>{data?.message ?? 'Financial execution, ledger posting, payouts, and provider settlement remain disabled. Security policy changes require a dedicated permission and are audited.'}</p></div></div>
    {active === 'Settings' && <SecuritySettingsPanel />}
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
