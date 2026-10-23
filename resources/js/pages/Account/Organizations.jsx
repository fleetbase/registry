import { useCallback, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import SiteLayout from '../../layouts/SiteLayout';
import AccountHeader from '../../components/account/AccountHeader';
import Dialog from '../../components/Dialog';
import Icon from '../../components/Icon';
import { StatusChip } from '../../components/Status';
import { useToast } from '../../components/Toasts';
import { EmptyState, Field, Note } from '../../components/Ui';
import { api } from '../../lib/api';
import { initials, plural } from '../../lib/format';
import { ROLES } from '../../lib/statuses';

const MANAGING = ['owner', 'admin'];

function roleLabel(role) {
    return ROLES[role]?.label ?? role;
}

/** An organization's avatar: its image when it has one, else its initials. */
function OrgAvatar({ organization, size = 36, radius }) {
    const style = { width: size, height: size, fontSize: Math.round(size / 3), borderRadius: radius ?? '50%' };
    if (organization.avatar_url) {
        return <img src={organization.avatar_url} alt="" className="avatar" style={{ ...style, objectFit: 'cover' }} />;
    }
    return (
        <span className="avatar" style={style} aria-hidden="true">
            {initials(organization.name)}
        </span>
    );
}

/**
 * Your organizations (left) and one of them (right): its public profile, its members and their
 * roles, adding people by email. Owners and admins edit; anyone can leave.
 */
export default function Organizations({ organizations = [], organization }) {
    const [creating, setCreating] = useState(false);
    // Dialogs take a stable onClose: a new function each render would move focus back to the start.
    const closeCreating = useCallback(() => setCreating(false), []);

    return (
        <SiteLayout title={organization ? `${organization.name} · Organizations` : 'Organizations'}>
            <div className="wrap" style={{ paddingTop: 40, paddingBottom: 80 }}>
                <div className="stack-lg">
                    <AccountHeader current="organizations" />
                    <div className="split">
                        <div className="rail" style={{ flexBasis: 360 }}>
                            <h2 className="h4">Your organizations</h2>
                            <nav className="col" style={{ gap: 10 }} aria-label="Your organizations">
                                {organizations.map((item) => (
                                    <Link key={item.id} href={`/account/organizations/${item.slug}`} className="org" aria-current={item.slug === organization?.slug ? 'page' : undefined} preserveScroll data-organization={item.slug}>
                                        <OrgAvatar organization={item} />
                                        <span className="col grow" style={{ gap: 0, minWidth: 0 }}>
                                            <span className="h4">{item.name}</span>
                                            <span className="xs muted">
                                                {item.is_personal
                                                    ? 'Personal · just you'
                                                    : [roleLabel(item.role), item.members_count != null ? plural(item.members_count, 'member') : null, item.is_verified ? 'verified' : null].filter(Boolean).join(' · ')}
                                            </span>
                                        </span>
                                    </Link>
                                ))}
                            </nav>
                            <button type="button" className="btn" style={{ width: '100%' }} onClick={() => setCreating(true)} data-new-organization>
                                <Icon name="plus" />
                                New organization
                            </button>
                            <p className="xs muted">An organization is a publisher (its name shows on listings) and a buyer (licences belong to it). Its slug becomes your npm scope and Composer vendor.</p>
                        </div>

                        <div className="main">
                            {organization ? (
                                <>
                                    <ProfileCard key={`profile-${organization.slug}`} organization={organization} />
                                    {organization.is_personal ? (
                                        <Note tone="info">
                                            This is your personal organization: it only ever has you. To publish with other people, <button type="button" style={{ border: 0, background: 'none', padding: 0, color: 'var(--accent)', font: 'inherit', textDecoration: 'underline', cursor: 'pointer' }} onClick={() => setCreating(true)}>create an organization</button>.
                                        </Note>
                                    ) : (
                                        <MembersCard key={`members-${organization.slug}`} organization={organization} />
                                    )}
                                </>
                            ) : (
                                <EmptyState icon="building" title="No organization yet" actions={<button type="button" className="btn btn-primary" onClick={() => setCreating(true)}>New organization</button>}>
                                    Create one to publish with other people.
                                </EmptyState>
                            )}
                        </div>
                    </div>
                </div>
            </div>
            <NewOrganizationDialog open={creating} onClose={closeCreating} />
        </SiteLayout>
    );
}

function ProfileCard({ organization }) {
    const toast = useToast();
    const canManage = MANAGING.includes(organization.role);
    const [form, setForm] = useState({ name: organization.name ?? '', website: organization.website ?? '', bio: organization.bio ?? '' });
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);

    async function save(event) {
        event.preventDefault();
        setBusy(true);
        setErrors({});
        try {
            await api.patch(`organizations/${organization.slug}`, { name: form.name.trim(), website: form.website.trim() || null, bio: form.bio.trim() || null });
            toast('Organization saved.');
            router.reload();
        } catch (error) {
            if (error.status === 422 && Object.keys(error.fields).length) {
                setErrors({ name: error.field('name'), website: error.field('website'), bio: error.field('bio') });
            } else {
                toast(error.message, { type: 'error' });
            }
        } finally {
            setBusy(false);
        }
    }

    return (
        <section className="card" aria-labelledby="h-org">
            <div className="card-head">
                <div className="row" style={{ gap: 14, minWidth: 0 }}>
                    <OrgAvatar organization={organization} size={48} radius={14} />
                    <div className="col" style={{ gap: 0, minWidth: 0 }}>
                        <div className="row wrapflex" style={{ gap: 8 }}>
                            <h2 id="h-org" className="h3">
                                {organization.name}
                            </h2>
                            {organization.is_verified && (
                                <StatusChip tone="ok" icon="shield">
                                    Verified
                                </StatusChip>
                            )}
                        </div>
                        <span className="small muted mono">@{organization.slug}</span>
                    </div>
                </div>
                <Link href={`/publishers/${organization.slug}`} className="btn btn-sm" style={{ minHeight: 44 }}>
                    Public profile
                </Link>
            </div>
            <form method="post" className="card-body stack" onSubmit={save} aria-label={`${organization.name} profile`} noValidate>
                {!canManage && <Note tone="info">Only owners and admins can edit this organization. Your role here is {roleLabel(organization.role)}.</Note>}
                <fieldset disabled={!canManage || busy} className="stack" style={{ border: 0, padding: 0, margin: 0 }}>
                    <div className="grid2">
                        <Field label="Display name" error={errors.name}>
                            {(id, aria) => <input id={id} {...aria} className={`input ${errors.name ? 'is-error' : ''}`} value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} />}
                        </Field>
                        <Field label="Website" optional error={errors.website}>
                            {(id, aria) => <input id={id} {...aria} className={`input ${errors.website ? 'is-error' : ''}`} type="url" inputMode="url" placeholder="https://" value={form.website} onChange={(event) => setForm({ ...form, website: event.target.value })} />}
                        </Field>
                    </div>
                    <Field label="Bio" optional="shown on your public profile" error={errors.bio}>
                        {(id, aria) => <textarea id={id} {...aria} className={`textarea ${errors.bio ? 'is-error' : ''}`} rows={3} value={form.bio} onChange={(event) => setForm({ ...form, bio: event.target.value })} />}
                    </Field>
                </fieldset>
                <div className="row between wrapflex">
                    <span className="small muted">
                        The slug <code>{organization.slug}</code> can’t change: published package names depend on it.
                    </span>
                    {canManage && (
                        <button type="submit" className="btn btn-primary" disabled={busy} data-save-organization>
                            {busy && <Icon name="spinner" spin />}
                            Save profile
                        </button>
                    )}
                </div>
            </form>
        </section>
    );
}

function MembersCard({ organization }) {
    const toast = useToast();
    const me = usePage().props.auth.account;
    const myRole = organization.role;
    const canManage = MANAGING.includes(myRole);
    const isOwner = myRole === 'owner';
    const members = organization.members ?? [];
    const [removing, setRemoving] = useState(null);
    const closeRemoving = useCallback(() => setRemoving(null), []);
    const [busy, setBusy] = useState(false);
    const [invite, setInvite] = useState({ email: '', role: 'publisher' });
    const [inviteError, setInviteError] = useState(null);
    const [inviting, setInviting] = useState(false);

    // Owners alone grant or take away the owner role.
    const assignable = Object.keys(ROLES).filter((role) => role !== 'owner' || isOwner);

    async function changeRole(member, role) {
        try {
            await api.patch(`organizations/${organization.slug}/members/${member.id}`, { role });
            toast(`${member.name} is now ${roleLabel(role).toLowerCase()}.`);
        } catch (error) {
            toast(error.message, { type: 'error' });
        }
        router.reload({ preserveScroll: true });
    }

    async function remove() {
        const member = removing;
        const leaving = member.id === me.id;
        setBusy(true);
        try {
            await api.delete(`organizations/${organization.slug}/members/${member.id}`);
            setRemoving(null);
            if (leaving) {
                toast(`You left ${organization.name}.`);
                router.visit('/account/organizations');
            } else {
                toast(`${member.name} was removed.`);
                router.reload({ preserveScroll: true });
            }
        } catch (error) {
            toast(error.message, { type: 'error' });
        } finally {
            setBusy(false);
        }
    }

    async function add(event) {
        event.preventDefault();
        setInviteError(null);
        setInviting(true);
        try {
            const member = await api.post(`organizations/${organization.slug}/members`, { email: invite.email.trim(), role: invite.role });
            setInvite({ email: '', role: invite.role });
            toast(`${member.name ?? member.email} was added as ${roleLabel(member.role).toLowerCase()}.`);
            router.reload({ preserveScroll: true });
        } catch (error) {
            setInviteError(error.field('email') ?? error.field('role') ?? (error.status === 422 && /register/i.test(error.message) ? 'No registry account uses that email. Ask them to sign up first, then add them.' : error.message));
        } finally {
            setInviting(false);
        }
    }

    return (
        <section className="card" aria-labelledby="h-members">
            <div className="card-head">
                <h2 id="h-members" className="h3">
                    Members
                </h2>
                <span className="small muted">{plural(members.length, 'person', 'people')}</span>
            </div>
            <ul className="card-body list" style={{ listStyle: 'none', margin: 0 }}>
                {members.map((member) => {
                    const you = member.id === me.id;
                    // Changing an owner takes an owner; nobody changes their own role here.
                    const locked = you || !canManage || (member.role === 'owner' && !isOwner);
                    const removable = you || (canManage && (member.role !== 'owner' || isOwner));
                    const selectId = `role-${member.id}`;
                    return (
                        <li key={member.id} className="li wrapflex" data-member={member.email}>
                            <span className="avatar" aria-hidden="true">
                                {initials(member.name)}
                            </span>
                            <span className="col grow" style={{ gap: 0, minWidth: 180 }}>
                                <span className="h4">
                                    {member.name} {you && <span className="xs muted">(you)</span>}
                                </span>
                                <span className="small muted" style={{ overflowWrap: 'anywhere' }}>
                                    {member.email}
                                </span>
                            </span>
                            <label className="sr-only" htmlFor={selectId}>
                                Role for {member.name}
                            </label>
                            <select id={selectId} className="role" value={member.role} disabled={locked} onChange={(event) => changeRole(member, event.target.value)}>
                                {(locked ? [member.role] : [...new Set([...assignable, member.role])]).map((role) => (
                                    <option key={role} value={role}>
                                        {roleLabel(role)}
                                    </option>
                                ))}
                            </select>
                            {you ? (
                                <button type="button" className="btn btn-sm btn-quiet" style={{ minHeight: 44 }} onClick={() => setRemoving(member)} data-leave>
                                    Leave
                                </button>
                            ) : removable ? (
                                <button type="button" className="icon-btn" aria-label={`Remove ${member.name}`} onClick={() => setRemoving(member)} data-remove-member={member.email}>
                                    <Icon name="trash" />
                                </button>
                            ) : (
                                <span style={{ width: 44 }} aria-hidden="true" />
                            )}
                        </li>
                    );
                })}
            </ul>

            {canManage && (
                <>
                    <div className="hr" />
                    <form method="post" className="card-body stack" aria-label="Add a member" onSubmit={add} noValidate>
                        <div className="row wrapflex" style={{ gap: 12, alignItems: 'flex-end' }}>
                            <Field label="Add a member by email" className="grow" error={inviteError}>
                                {(id, aria) => (
                                    <input
                                        id={id}
                                        {...aria}
                                        className={`input ${inviteError ? 'is-error' : ''}`}
                                        style={{ minWidth: 220 }}
                                        type="email"
                                        autoComplete="off"
                                        value={invite.email}
                                        onChange={(event) => setInvite({ ...invite, email: event.target.value })}
                                    />
                                )}
                            </Field>
                            <Field label="Role">
                                {(id, aria) => (
                                    <select id={id} {...aria} className="select" style={{ width: 160 }} value={invite.role} onChange={(event) => setInvite({ ...invite, role: event.target.value })}>
                                        {assignable.map((role) => (
                                            <option key={role} value={role}>
                                                {roleLabel(role)}
                                            </option>
                                        ))}
                                    </select>
                                )}
                            </Field>
                            <button type="submit" className="btn btn-primary" disabled={inviting || !invite.email.trim()} data-add-member>
                                {inviting && <Icon name="spinner" spin />}
                                Add member
                            </button>
                        </div>
                        <dl className="grid4 small" style={{ gap: 10, margin: 0 }}>
                            {Object.entries(ROLES).map(([role, { label, description }]) => (
                                <div key={role} className="col" style={{ gap: 2 }}>
                                    <dt>
                                        <strong>{label}</strong>
                                    </dt>
                                    <dd className="muted" style={{ margin: 0 }}>
                                        {description}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </form>
                </>
            )}

            <Dialog
                open={Boolean(removing)}
                title={removing?.id === me.id ? `Leave ${organization.name}?` : `Remove ${removing?.name}?`}
                labelledBy="remove-member-title"
                onClose={closeRemoving}
                footer={
                    <>
                        <button type="button" className="btn" onClick={() => setRemoving(null)}>
                            Cancel
                        </button>
                        <button type="button" className="btn btn-danger-solid" onClick={remove} disabled={busy} data-confirm-remove>
                            {busy && <Icon name="spinner" spin />}
                            {removing?.id === me.id ? 'Leave organization' : 'Remove member'}
                        </button>
                    </>
                }
            >
                <p className="ink2">
                    {removing?.id === me.id
                        ? `You lose access to ${organization.name}’s listings, releases and licences. An owner or admin can add you again.`
                        : `${removing?.name} (${removing?.email}) loses access to ${organization.name}’s listings, releases and licences. You can add them again later.`}
                </p>
            </Dialog>
        </section>
    );
}

function NewOrganizationDialog({ open, onClose }) {
    const toast = useToast();
    const [form, setForm] = useState({ name: '', slug: '', website: '' });
    const [errors, setErrors] = useState({});
    const [failure, setFailure] = useState(null);
    const [busy, setBusy] = useState(false);

    const close = useCallback(() => {
        setErrors({});
        setFailure(null);
        onClose();
    }, [onClose]);

    async function create(event) {
        event.preventDefault();
        setBusy(true);
        setErrors({});
        setFailure(null);
        try {
            const organization = await api.post('organizations', { name: form.name.trim(), slug: form.slug.trim() || undefined, website: form.website.trim() || undefined });
            setForm({ name: '', slug: '', website: '' });
            onClose();
            toast(`${organization.name} created. You are its owner.`);
            router.visit(`/account/organizations/${organization.slug}`);
        } catch (error) {
            if (error.status === 422 && Object.keys(error.fields).length) {
                setErrors({ name: error.field('name'), slug: error.field('slug'), website: error.field('website') });
            } else {
                setFailure(error.message);
            }
        } finally {
            setBusy(false);
        }
    }

    return (
        <Dialog
            open={open}
            title="New organization"
            labelledBy="new-organization-title"
            onClose={close}
            footer={
                <>
                    <button type="button" className="btn" onClick={close}>
                        Cancel
                    </button>
                    <button type="submit" form="new-organization" className="btn btn-primary" disabled={busy || !form.name.trim()} data-create-organization>
                        {busy && <Icon name="spinner" spin />}
                        Create organization
                    </button>
                </>
            }
        >
            <form method="post" id="new-organization" className="stack" onSubmit={create} noValidate>
                {failure && (
                    <Note tone="fail" role="alert">
                        {failure}
                    </Note>
                )}
                <Field label="Name" error={errors.name}>
                    {(id, aria) => <input id={id} {...aria} data-autofocus className={`input ${errors.name ? 'is-error' : ''}`} value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} />}
                </Field>
                <Field label="Slug" optional error={errors.slug} help="Your npm scope and Composer vendor, like @acme. It can’t change later. Left empty, it comes from the name.">
                    {(id, aria) => (
                        <input id={id} {...aria} className={`input mono ${errors.slug ? 'is-error' : ''}`} autoCapitalize="none" spellCheck={false} value={form.slug} onChange={(event) => setForm({ ...form, slug: event.target.value })} />
                    )}
                </Field>
                <Field label="Website" optional error={errors.website}>
                    {(id, aria) => <input id={id} {...aria} className={`input ${errors.website ? 'is-error' : ''}`} type="url" inputMode="url" placeholder="https://" value={form.website} onChange={(event) => setForm({ ...form, website: event.target.value })} />}
                </Field>
            </form>
        </Dialog>
    );
}
