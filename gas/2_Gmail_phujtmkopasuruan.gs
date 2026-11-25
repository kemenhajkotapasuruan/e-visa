/**
 * ============================================================================
 * GOOGLE APPS SCRIPT ALL-IN-ONE (BISA UNTUK GDRIVE & GMAIL SEKALIGUS)
 * - Mendukung Penyimpanan Otomatis ke Google Drive (doPost)
 * - Mendukung Pembacaan Email "Mofa Enrollment Confirmation" (doGet)
 * ============================================================================
 * CARA UPDATE / DEPLOY ULANG (PENTING!):
 * 1. Buka project Anda di https://script.google.com
 *    - Untuk GDrive : pastikan login akun muchammad.ali.sanjaya@gmail.com
 *    - Untuk Gmail  : pastikan login akun phujtmkopasuruan@gmail.com
 * 2. Hapus seluruh kode lama di editor Code.gs, lalu TEMPEL (Paste) seluruh kode ini.
 * 3. Tekan Ctrl+S (Simpan).
 * 4. PENTING: Agar izin Google Drive & Gmail aktif, pada toolbar atas di sebelah
 *    tombol "Run / Jalankan", pilih fungsi "cekIzinAkses" lalu klik "Run / Jalankan"
 *    dan klik "Review Permissions -> Allow / Izinkan".
 * 5. Klik tombol "Deploy" di kanan atas -> pilih "New deployment" (Deployment baru).
 * 6. Pilih jenis "Web app":
 *    - Execute as : Me
 *    - Who has access (Siapa yang memiliki akses) : Anyone (Siapa saja)
 * 7. Klik "Deploy", lalu SALIN "Web app URL" yang baru ke aplikasi Laragon.
 * ============================================================================
 */

var DEFAULT_FOLDER_NAME = 'Biovisa_Jamaah_PHU_Pasuruan';

/**
 * Jalankan fungsi ini 1x di editor (klik tombol Run) untuk memicu izin Google Drive & Gmail
 */
function cekIzinAkses() {
  var emailAkun = Session.getEffectiveUser().getEmail();
  var rootFolder = DriveApp.getRootFolder().getName();
  var inboxThreads = GmailApp.getInboxThreads(0, 1);
  Logger.log('Izin berhasil diaktifkan untuk akun: ' + emailAkun + ' | Drive: ' + rootFolder + ' | Inbox OK (' + inboxThreads.length + ')');
}

/**
 * doGet: Digunakan untuk:
 * 1. Menarik email "Mofa Enrollment Confirmation" (jika parameter action=fetch_mofa)
 * 2. Mengecek status koneksi & akun aktif (jika dipanggil tanpa parameter atau action=ping)
 */
function doGet(e) {
  try {
    var params = (e && e.parameter) ? e.parameter : {};
    var action = params.action || 'ping';
    var activeEmail = '';
    try {
      activeEmail = Session.getEffectiveUser().getEmail() || Session.getActiveUser().getEmail() || '';
    } catch (errUser) {}

    if (action === 'ping' || action === 'status') {
      return jsonOut({
        ok: true,
        service: 'Auto-BioVisa All-in-One (Gmail + GDrive 7-Dokumen Ready)',
        supports_post: true,
        supports_get: true,
        account_email: activeEmail,
        status: 'READY'
      });
    }

    // Ambil 1 file dari Google Drive dalam bentuk Base64 (untuk sinkronisasi 7 Dokumen dari GDrive ke Server/Siskohat)
    if (action === 'get_gdrive_file' && params.file_id) {
      var gFile = DriveApp.getFileById(params.file_id);
      var blob = gFile.getBlob();
      return jsonOut({
        ok: true,
        file_id: gFile.getId(),
        filename: gFile.getName(),
        mime_type: blob.getContentType(),
        size_bytes: gFile.getSize(),
        base64: Utilities.base64Encode(blob.getBytes())
      });
    }

    // Daftar seluruh file pada 7 Sub-Folder Dokumen di Google Drive
    if (action === 'list_gdrive_docs') {
      var rootFolder = getOrCreateTargetFolder(params.folder_name || DEFAULT_FOLDER_NAME, params.folder_id || '');
      var catMap = {
        'paspor': ['1_Paspor', 'Paspor', 'paspor'],
        'endorsement': ['2_Endorsement', 'Endorsement', 'endorsement'],
        'vaksin': ['3_Vaksin', 'Vaksin', 'vaksin'],
        'kitas': ['4_Kitas', 'Kitas', 'KITAS', 'kitas'],
        'pendukung': ['5_Pendukung', 'Pendukung', 'pendukung'],
        'biovisa': ['6_Biovisa', 'Biovisa', 'biovisa'],
        'foto': ['7_Foto', 'Foto', 'foto']
      };
      var resultDocs = {};
      for (var catKey in catMap) {
        resultDocs[catKey] = [];
        var aliases = catMap[catKey];
        var subFolder = null;
        for (var a = 0; a < aliases.length; a++) {
          var sfIt = rootFolder.getFoldersByName(aliases[a]);
          if (sfIt.hasNext()) {
            subFolder = sfIt.next();
            break;
          }
        }
        if (!subFolder) {
          // Buat sub-folder resmi otomatis jika belum ada
          subFolder = rootFolder.createFolder(aliases[0]);
        }
        var fIt = subFolder.getFiles();
        while (fIt.hasNext()) {
          var f = fIt.next();
          resultDocs[catKey].push({
            file_id: f.getId(),
            filename: f.getName(),
            size_bytes: f.getSize(),
            size_kb: Math.round((f.getSize() / 1024) * 100) / 100,
            url: f.getUrl()
          });
        }
      }
      return jsonOut({
        ok: true,
        account_email: activeEmail,
        root_folder_id: rootFolder.getId(),
        root_folder_url: rootFolder.getUrl(),
        categories: resultDocs
      });
    }

    var subjectQuery = params.subject || 'Enrollment confirmation';
    var limit = parseInt(params.limit || '100', 10);
    if (isNaN(limit) || limit < 1) limit = 100;
    if (limit > 150) limit = 150;

    var query = 'subject:"Enrollment confirmation"';
    var threads = GmailApp.search(query, 0, limit);
    var emails = [];

    for (var i = 0; i < threads.length; i++) {
      var messages = threads[i].getMessages();
      for (var j = 0; j < messages.length; j++) {
        var msg = messages[j];
        var subj = msg.getSubject() || '';
        if (subj.toLowerCase().indexOf('enrollment confirmation') === -1) {
          continue;
        }
        var plainBody = msg.getPlainBody() || '';
        if (plainBody.indexOf('One Time Password') !== -1) {
          continue;
        }
        // Jangan tolak jika ada "passport number" meskipun ada kutipan "under review" di thread yang sama
        if (plainBody.indexOf('under review') !== -1 && plainBody.toLowerCase().indexOf('passport number') === -1) {
          continue;
        }

        emails.push({
          message_id: msg.getId(),
          subject: subj,
          from: msg.getFrom(),
          to: msg.getTo(),
          date: Utilities.formatDate(msg.getDate(), 'Asia/Jakarta', 'dd MMM yyyy, HH:mm:ss') + ' WIB',
          body_text: plainBody,
          body_html: '',
          attachments: []
        });
      }
    }

    return jsonOut({
      ok: true,
      account_email: activeEmail,
      query_used: query,
      count: emails.length,
      emails: emails
    });

  } catch (err) {
    return jsonOut({
      ok: false,
      error: err.toString(),
      emails: []
    });
  }
}

/**
 * doPost: Digunakan untuk menyimpan file JPG Biovisa (Nama_Nomor paspor.jpg <= 50KB) ke Google Drive
 */
function doPost(e) {
  try {
    var raw = (e && e.postData && e.postData.contents) ? e.postData.contents : '{}';
    var data = JSON.parse(raw);

    var activeEmail = '';
    try {
      activeEmail = Session.getEffectiveUser().getEmail() || '';
    } catch (errUser) {}

    if (data.action === 'ping_post') {
      var testFolder = getOrCreateTargetFolder(data.folder_name || DEFAULT_FOLDER_NAME, data.folder_id || '');
      return jsonOut({
        ok: true,
        account_email: activeEmail,
        folder_id: testFolder.getId(),
        folder_name: testFolder.getName(),
        folder_url: testFolder.getUrl(),
        message: 'Koneksi tulis Google Drive berhasil pada akun ' + (activeEmail || 'ini')
      });
    }

    var filename   = data.filename || ('JAMAAH_' + new Date().getTime() + '.jpg');
    var base64File = data.base64_file || '';
    var folderName = data.folder_name || DEFAULT_FOLDER_NAME;
    var folderId   = data.folder_id || '';
    var subfolderName = data.subfolder || '';

    if (!base64File) {
      return jsonOut({ ok: false, error: 'Data base64_file kosong.' });
    }

    var rootFolder = getOrCreateTargetFolder(folderName, folderId);
    var folder = rootFolder;
    if (subfolderName) {
      var subIt = rootFolder.getFoldersByName(subfolderName);
      if (subIt.hasNext()) {
        folder = subIt.next();
      } else {
        folder = rootFolder.createFolder(subfolderName);
      }
    }

    // Hapus file lama dengan nama yang sama di folder tersebut agar tidak duplikat
    var existingFiles = folder.getFilesByName(filename);
    while (existingFiles.hasNext()) {
      var oldFile = existingFiles.next();
      oldFile.setTrashed(true);
    }

    // Simpan file JPG Biovisa (Nama_Nomor paspor.jpg <= 50KB)
    var decodedBytes = Utilities.base64Decode(base64File);
    var blob = Utilities.newBlob(decodedBytes, 'image/jpeg', filename);
    var createdFile = folder.createFile(blob);
    createdFile.setDescription(
      'Biovisa Jamaah: ' + (data.nama || '-') +
      ' | Paspor: ' + (data.paspor || '-') +
      ' | Enrollment ID: ' + (data.enrollment_id || '-') +
      ' | Ukuran: ' + (data.size_kb || '-') + ' KB'
    );

    return jsonOut({
      ok: true,
      account_email: activeEmail,
      file_id: createdFile.getId(),
      file_url: createdFile.getUrl(),
      folder_id: folder.getId(),
      folder_url: folder.getUrl(),
      filename: filename
    });
  } catch (err) {
    return jsonOut({
      ok: false,
      error: err.toString()
    });
  }
}

function getOrCreateTargetFolder(folderName, folderId) {
  var folder = null;
  if (folderId) {
    try {
      folder = DriveApp.getFolderById(folderId);
    } catch (errFolder) {
      folder = null;
    }
  }
  if (!folder) {
    var folders = DriveApp.getFoldersByName(folderName);
    if (folders.hasNext()) {
      folder = folders.next();
    } else {
      folder = DriveApp.createFolder(folderName);
    }
  }
  return folder;
}

function jsonOut(obj) {
  return ContentService.createTextOutput(JSON.stringify(obj))
    .setMimeType(ContentService.MimeType.JSON);
}
