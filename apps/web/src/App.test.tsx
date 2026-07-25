import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import App from '@/App';

describe('App shell', () => {
  it('renders the application name', () => {
    render(<App />);
    expect(screen.getByRole('heading', { name: /eruofood ai/i })).toBeInTheDocument();
  });
});
