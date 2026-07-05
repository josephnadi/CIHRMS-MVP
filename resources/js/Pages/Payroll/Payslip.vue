<script setup>
import { onMounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';

defineOptions({ layout: null }); // standalone, prints clean

const props = defineProps({
    org:     { type: Object, required: true },
    run:     { type: Object, required: true },
    payslip: { type: Object, required: true },
});

const ghs = (v) => 'GHS ' + (Number(v) || 0).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const printNow = () => window.print();
onMounted(() => setTimeout(printNow, 350));
</script>

<template>
    <Head :title="`Payslip — ${payslip.employee.name}`" />
    <div class="page">
        <div class="no-print toolbar">
            <Link :href="route('payroll-runs.index')" class="lnk">← Runs</Link>
            <button type="button" class="btn" @click="printNow">Print</button>
        </div>

        <article class="sheet">
            <header class="head">
                <div>
                    <h1 class="org">{{ org.name }}</h1>
                    <p class="muted">Payslip · {{ run.period_label }}</p>
                </div>
                <div class="meta">
                    <div class="ref">{{ run.reference }}</div>
                </div>
            </header>

            <section class="who">
                <div><span class="lbl">Employee</span><span class="val strong">{{ payslip.employee.name }}</span></div>
                <div v-if="payslip.employee.employee_no"><span class="lbl">Staff No</span><span class="val">{{ payslip.employee.employee_no }}</span></div>
                <div v-if="payslip.employee.department"><span class="lbl">Department</span><span class="val">{{ payslip.employee.department }}</span></div>
                <div v-if="payslip.employee.grade"><span class="lbl">Grade / Step</span><span class="val">{{ payslip.employee.grade }} / {{ payslip.employee.step }}</span></div>
            </section>

            <table class="calc">
                <tbody>
                    <tr class="row"><td>Basic Salary</td><td class="r">{{ ghs(payslip.basic) }}</td></tr>

                    <tr v-for="(l, i) in payslip.taxable_lines" :key="'t'+i" class="row sub">
                        <td>Add: {{ l.label }}</td><td class="r">{{ ghs(l.amount) }}</td>
                    </tr>

                    <tr class="row total"><td>Assessable Income</td><td class="r">{{ ghs(payslip.assessable_income) }}</td></tr>

                    <tr class="row sub neg"><td>Less: Social Security Fund (SSF)</td><td class="r">({{ ghs(payslip.ssf) }})</td></tr>
                    <tr class="row sub neg"><td>Less: Provident Fund</td><td class="r">({{ ghs(payslip.provident) }})</td></tr>

                    <tr class="row total"><td>Chargeable Income</td><td class="r">{{ ghs(payslip.chargeable_income) }}</td></tr>

                    <tr class="row sub neg"><td>Less: PAYE</td><td class="r">({{ ghs(payslip.paye) }})</td></tr>

                    <tr class="row total"><td>Net Salary</td><td class="r">{{ ghs(payslip.net_salary) }}</td></tr>

                    <tr v-for="(l, i) in payslip.transport_lines" :key="'x'+i" class="row sub">
                        <td>Add: {{ l.label }}</td><td class="r">{{ ghs(l.amount) }}</td>
                    </tr>

                    <tr v-if="payslip.deductions" class="row sub neg"><td>Less: Loan / Deductions</td><td class="r">({{ ghs(payslip.deductions) }})</td></tr>

                    <tr class="row grand"><td>Take Home</td><td class="r">{{ ghs(payslip.take_home) }}</td></tr>
                </tbody>
            </table>

            <footer class="foot muted">
                Reliefs (SSF &amp; Provident) reduce taxable income; the transport refund is non-taxable.
                Computed from {{ org.name }} payroll · {{ run.reference }}.
            </footer>
        </article>
    </div>
</template>

<style scoped>
.page { background:#f3f4f6; min-height:100vh; padding:24px; font-family: ui-sans-serif, system-ui, sans-serif; color:#111827; }
.toolbar { max-width:640px; margin:0 auto 16px; display:flex; justify-content:space-between; }
.lnk { color:#475569; font-weight:700; font-size:13px; text-decoration:none; }
.btn { background:#0d1452; color:#fff; border:0; border-radius:8px; padding:8px 18px; font-weight:700; font-size:13px; cursor:pointer; }
.sheet { max-width:640px; margin:0 auto; background:#fff; padding:44px; box-shadow:0 1px 4px rgba(0,0,0,.08); border-radius:8px; }
.head { display:flex; justify-content:space-between; align-items:flex-start; border-bottom:2px solid #0d1452; padding-bottom:14px; }
.org { font-size:20px; font-weight:800; margin:0; color:#0d1452; }
.ref { font-family: ui-monospace, monospace; font-weight:800; font-size:14px; }
.muted { color:#6b7280; }
.who { display:grid; grid-template-columns:1fr 1fr; gap:6px 24px; margin:20px 0 8px; font-size:13px; }
.who > div { display:flex; justify-content:space-between; border-bottom:1px dotted #e5e7eb; padding:3px 0; }
.lbl { color:#6b7280; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; }
.val { font-weight:600; } .strong { font-weight:800; }
.calc { width:100%; border-collapse:collapse; margin-top:16px; font-size:13.5px; }
.calc td { padding:7px 4px; }
.calc .r { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
.row td:first-child { color:#374151; }
.sub td:first-child { padding-left:18px; color:#6b7280; }
.neg .r { color:#b91c1c; }
.total td { border-top:1px solid #d1d5db; font-weight:800; }
.grand td { border-top:2px solid #0d1452; border-bottom:2px solid #0d1452; font-weight:900; font-size:15px; }
.foot { margin-top:30px; padding-top:12px; border-top:1px solid #e5e7eb; font-size:11px; }
@media print {
    .page { background:#fff; padding:0; }
    .no-print { display:none !important; }
    .sheet { box-shadow:none; max-width:none; padding:0; border-radius:0; }
}
</style>
