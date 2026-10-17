/** Labels shared by the HR screens. */
export const roleTypes: Record<string, string> = { cashier: 'Cashier', sales_agent: 'Sales agent', rider: 'Rider', warehouse: 'Godown / warehouse', supervisor: 'Supervisor', manager: 'Manager', admin: 'Office / admin', other: 'Other' };
export const employmentTypes: Record<string, string> = { permanent: 'Permanent', probation: 'On probation', contract: 'Contract', daily_wage: 'Daily wage', part_time: 'Part time' };
export const docTypes: Record<string, string> = { cnic: 'CNIC copy', contract: 'Contract', cv: 'CV', certificate: 'Certificate', police_verification: 'Police verification', medical: 'Medical', photo: 'Photo', other: 'Other' };
export const attendanceStatus: Record<string, string> = { present: 'Present', late: 'Late', absent: 'Absent', half_day: 'Half day', on_leave: 'On leave', holiday: 'Holiday', off: 'Day off' };
export function minutes(m: number | null | undefined): string {
    if (!m) return '—';
    const h = Math.floor(m / 60);
    return h ? `${h}h ${m % 60}m` : `${m}m`;
}
export function clock(v: string | null | undefined): string {
    if (!v) return '—';
    return new Date(v).toLocaleTimeString('en-PK', { hour: '2-digit', minute: '2-digit', hour12: true, timeZone: 'Asia/Karachi' });
}
