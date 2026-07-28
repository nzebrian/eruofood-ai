import { useCallback, useEffect, useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { config } from '@config/env';
import { tokenStorage } from '@lib/tokenStorage';
import { analyticsApi } from '../analyticsApi';
import type { Report } from '../types';

/** Generate analytics reports and download them (CSV / XLSX / PDF). */
export function ReportsPage(): React.JSX.Element {
  const [catalogue, setCatalogue] = useState<string[]>([]);
  const [selected, setSelected] = useState('financial');
  const [days, setDays] = useState(30);
  const [report, setReport] = useState<Report | null>(null);
  const [busy, setBusy] = useState(false);

  const loadCatalogue = useCallback((): void => {
    analyticsApi
      .catalogue()
      .then((c) => {
        setCatalogue(c.reports);
        const first = c.reports[0];
        if (first) setSelected((s) => (c.reports.includes(s) ? s : first));
      })
      .catch(() => setCatalogue([]));
  }, []);

  useEffect(loadCatalogue, [loadCatalogue]);

  async function generate(): Promise<void> {
    setBusy(true);
    try {
      setReport(await analyticsApi.generate(selected, days));
    } finally {
      setBusy(false);
    }
  }

  async function download(format: string): Promise<void> {
    if (!report) return;
    const tokens = tokenStorage.get();
    const res = await fetch(`${config.apiBaseUrl}${analyticsApi.exportPath(report.id, format)}`, {
      headers: tokens ? { Authorization: `Bearer ${tokens.accessToken}` } : {},
    });
    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `${report.key}.${format}`;
    a.click();
    URL.revokeObjectURL(url);
  }

  return (
    <Layout>
      <h1>Reports</h1>

      <div className="bi-filters">
        <select value={selected} onChange={(e) => setSelected(e.target.value)} aria-label="Report">
          {catalogue.map((r) => (
            <option key={r} value={r}>
              {r.replace(/_/g, ' ')}
            </option>
          ))}
        </select>
        <select value={days} onChange={(e) => setDays(Number(e.target.value))} aria-label="Range">
          <option value={7}>Last 7 days</option>
          <option value={30}>Last 30 days</option>
          <option value={90}>Last 90 days</option>
        </select>
        <Button onClick={() => void generate()} busy={busy}>
          Generate
        </Button>
      </div>

      {report && (
        <>
          <div className="bi-export">
            Download:
            <button className="bi-link" onClick={() => void download('csv')}>
              CSV
            </button>
            <button className="bi-link" onClick={() => void download('xlsx')}>
              Excel
            </button>
            <button className="bi-link" onClick={() => void download('pdf')}>
              PDF
            </button>
          </div>
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
        </>
      )}
    </Layout>
  );
}
