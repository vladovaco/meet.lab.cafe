/* Lokálne úložisko nahrávky (IndexedDB) – poistka proti pádu prehliadača / vybitiu telefónu.
 * Každý blok z MediaRecorderu sa hneď zapíše; po úspešnom uploade sa nahrávka zmaže. */
window.RecStore = (function () {
  const DB = 'meetlab-rec', VER = 1;
  let dbPromise = null;

  function open() {
    if (dbPromise) return dbPromise;
    dbPromise = new Promise((resolve, reject) => {
      if (!('indexedDB' in window)) { reject(new Error('IndexedDB nie je k dispozícii')); return; }
      const req = indexedDB.open(DB, VER);
      req.onupgradeneeded = () => {
        const db = req.result;
        if (!db.objectStoreNames.contains('sessions')) db.createObjectStore('sessions', { keyPath: 'id' });
        if (!db.objectStoreNames.contains('chunks')) {
          const s = db.createObjectStore('chunks', { keyPath: ['session', 'index'] });
          s.createIndex('bySession', 'session');
        }
      };
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error);
    });
    return dbPromise;
  }

  function tx(store, mode, fn) {
    return open().then(db => new Promise((resolve, reject) => {
      const t = db.transaction(store, mode);
      const s = t.objectStore(store);
      const out = fn(s);
      t.oncomplete = () => resolve(out && out.result !== undefined ? out.result : out);
      t.onerror = () => reject(t.error);
      t.onabort = () => reject(t.error);
    }));
  }

  return {
    available: () => 'indexedDB' in window,
    createSession: (meta) => tx('sessions', 'readwrite', s => s.put({ id: meta.id, startedAt: meta.startedAt, mimeType: meta.mimeType, elapsed: 0, chunks: 0, bytes: 0, title: meta.title || '' })),
    updateSession: (id, patch) => tx('sessions', 'readwrite', s => {
      const g = s.get(id);
      g.onsuccess = () => { if (g.result) s.put(Object.assign(g.result, patch)); };
    }),
    addChunk: (session, index, blob) => tx('chunks', 'readwrite', s => s.put({ session, index, blob })),
    listSessions: () => tx('sessions', 'readonly', s => s.getAll()),
    getChunks: (session) => tx('chunks', 'readonly', s => s.index('bySession').getAll(IDBKeyRange.only(session))),
    deleteSession: async (session) => {
      await tx('chunks', 'readwrite', s => {
        const req = s.index('bySession').openKeyCursor(IDBKeyRange.only(session));
        req.onsuccess = () => { const c = req.result; if (c) { s.delete(c.primaryKey); c.continue(); } };
      });
      await tx('sessions', 'readwrite', s => s.delete(session));
    },
  };
})();
