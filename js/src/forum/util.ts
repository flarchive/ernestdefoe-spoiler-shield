import app from 'flarum/forum/app';
import type Mithril from 'mithril';

declare const m: Mithril.Static;

/** What the server says about a marked discussion or post (the `spoilerShield` attribute). */
export interface Shield {
  work: string;
  title: string;
  kind: string;
  position: number | null;
  label: string | null;
  hidden: boolean;
  revealed: boolean;
  viewer: { position: number; label: string } | null;
  offer: boolean;
}

export const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-spoiler-shield.forum.${key}`, params);

export const shieldOf = (model: any): Shield | null => (model && model.attribute ? model.attribute('spoilerShield') || null : null);

export const api = (method: string, path: string, body?: any) =>
  app.request<any>({ method, url: `${app.forum.attribute('apiUrl')}/spoiler-shield${path}`, body });

let chunk: Promise<typeof import('./chunk')> | null = null;
/** The modals and the progress page: fetched the first time someone opens one. */
export const loadChunk = () => (chunk ??= import('./chunk'));

/** After progress changes, what's on screen is stale: lists refetch; a discussion reloads its page. */
export function refreshAfterProgress() {
  if (app.current.get('routeName') === 'discussion' || app.current.get('routeName') === 'discussion.near') {
    window.location.reload();
    return;
  }
  (app as any).discussions?.refresh?.();
}

/** Show this one anyway: the server remembers, and sends it back whole. */
export async function reveal(type: 'discussions' | 'posts', id: string) {
  const doc = await api('POST', '/reveals', { type, id: Number(id) });
  app.store.pushPayload(doc);
  // A discussion's own posts open with it: those already on screen still carry their veils.
  const loaded = type === 'discussions' ? app.store.all<any>('posts').filter((p) => p.discussion?.()?.id?.() === id && shieldOf(p)?.hidden) : [];
  if (loaded.length) {
    const ids = loaded.map((p) => p.id()).join(',');
    app.store.pushPayload(await app.request<any>({ method: 'GET', url: `${app.forum.attribute('apiUrl')}/posts`, params: { filter: { id: ids } } }));
  }
  m.redraw();
}

export function openProgress(work: string) {
  loadChunk().then((c) => app.modal.show(c.ProgressModal, { work }));
}

/** "Finished: unlock everything" (from the Curio offer). */
export async function finish(work: string) {
  await api('POST', '/progress', { work, position: 'finished' });
  refreshAfterProgress();
}
