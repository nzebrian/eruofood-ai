import { useEffect, useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { EmptyState, ErrorState } from '@shared/components/StateViews';
import { describeError, useAsyncData } from '@shared/hooks/useAsyncData';
import { config } from '@config/env';
import { tokenStorage } from '@lib/tokenStorage';
import { analyticsApi } from '../analyticsApi';
import type { Report } from '../types';

/** Generate analytics reports and download them (CSV / XLSX / PDF). */
export function ReportsPage(): React.JSX.Element {
  const [selected, setSelected] = useState('financial');
  const [days, setDays] = useState(30);
  const [report, setReport] = useState<Report | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const catalogue = useAsyncData(() => analyticsApi.catalogue(), 'analytics|report-catalogue');
  const reports = catalogue.state.status === 'ready' ? catalogue.state.data.reports : [];

  // Keep the selection valid once the catalogue arrives, without overriding a
  // choice the analyst has already made.
  const firstReport = reports[0] ?? '';
  const selectionIsValid = reports.includes(selected);
  useEffect(() => {
    if (firstReport !== '' && !selectionIsValid) setSelected(firstReport);
  }, [firstReport, selectionIsValid]);

  async function generate(): Promise<void> {
    setBusy(true);
    setError(null);
    try {
      setReport(await analyticsApi.generate(selected, days));
    } catch (err) {
      // `try { … } finally { … }` with no `catch` left this as an unhandled
      // rejection: the spinner stopped and nothing else happened.
      setError(describeError(err, 'Could not generate that report.'));
    } finally {
      setBusy(false);
    }
  }

  async function download(format: string): Promise<void> {
    if (!report) return;
    setError(null);
    try {
      const tokens = tokenStorage.get();
      const res = await fetch(`${config.apiBaseUrl}${analyticsApi.exportPath(report.id, format)}`, {
        headers: tokens ? { Authorization: `Bearer ${tokens.accessToken}` } : {},
      });
      // `res.ok` was never checked, so a 500 was saved to disk as
      // `financial.csv` — an error page wearing a spreadsheet's file name.
      if (!res.ok) {
        setError(`The ${format.toUpperCase()} export failed (${String(res.status)}).`);
        return;
      }
      const blob = await res.blob();
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `${report.key}.${format}`;
      a.click();
      URL.revokeObjectURL(url);
    } catch (err) {
      setError(describeError(err, `The ${format.toUpperCase()} export failed.`));
    }
  }

  return (
    <Layout>
      <h1>Reports</h1>

      <div className="bi-filters">
        <select
          value={selected}
          onChange={(e) => setSelected(e.target.value)}
          aria-label="Report type"
        >
          {reports.map((r) => (
            <option key={r} value={r}>
              {r.replace(/_/g, ' ')}
            </option>
          ))}
        </select>
        <select
          value={days}
          onChange={(e) => setDays(Number(e.target.value))}
          aria-label="Date range"
        >
          <option value={7}>Last 7 days</option>
          <option value={30}>Last 30 days</option>
          <option value={90}>Last 90 days</option>
        </select>
        <Button onClick={() => void generate()} busy={busy}>
          Generate
        </Button>
      </div>

      {error !== null ? <ErrorState message={error} title="That did not work" /> : null}

      {report === null ? (
        <EmptyState
          title="No report generated yet"
          description="Pick a report and a date range, then choose Generate."
        />
      ) : (
        <>
          <div className="bi-export">
            Download:
            <button type="button" className="bi-link" onClick={() => void download('csv')}>
              CSV
            </button>
            <button type="button" className="bi-link" onClick={() => void download('xlsx')}>
              Excel
            </button>
            <button type="button" className="bi-link" onClick={() => void download('pdf')}>
              PDF
            </button>
          </div>
          <div className="table-scroll">
            <table className="bi-table">
              <thead>
                <tr>
                  {report.columns.map((c) => (
                    <th key={c}>{c}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {report.rows.map((row, i) => (
                  <tr key={i}>
                    {row.map((cell, j) => (
                      <td key={j}>{String(cell)}</td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </Layout>
  );
}
