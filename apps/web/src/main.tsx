import { loadLocale } from '@wingora/localization';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { App } from './app';
import './styles/index.css';

// Fetch a non-English catalog before the first paint so Spanish users never see English flash; English needs no request.
// If the catalog can't be fetched the app still renders, in English.
void loadLocale(navigator.language).catch(() => undefined).finally(() => {
  createRoot(document.getElementById('root')!).render(
    <StrictMode>
      <App />
    </StrictMode>,
  );
});
