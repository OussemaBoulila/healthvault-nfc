document.getElementById('btnWebNFC')?.addEventListener('click', async () => {
  const notify = (msg, type) => (typeof toast === 'function' ? toast(msg, type) : alert(msg));

  if (!('NDEFReader' in window)) {
    notify('Web NFC non supporté sur ce navigateur. Utilisez Chrome sous Android ou entrez le token manuellement.', 'error');
    return;
  }

  try {
    const ndef = new NDEFReader();
    await ndef.scan();
    notify('Prêt : approchez la carte du téléphone...');

    ndef.onreading = (event) => {
      const decoder = new TextDecoder();
      for (const record of event.message.records) {
        if (record.recordType === 'text') {
          document.getElementById('tokenInput').value = decoder.decode(record.data).trim();
        } else if (record.recordType === 'url') {
          const url = decoder.decode(record.data);
          const query = url.includes('?') ? url.split('?')[1] : '';
          const params = new URLSearchParams(query);
          if (params.has('t')) {
            document.getElementById('tokenInput').value = params.get('t');
          }
        }
      }
      // PIN entry stays manual: the patient's consent must be given explicitly.
      document.getElementById('pinInput').focus();
    };
  } catch (error) {
    console.error('Erreur NFC:', error);
    notify('Erreur Web NFC : ' + error.message, 'error');
  }
});
