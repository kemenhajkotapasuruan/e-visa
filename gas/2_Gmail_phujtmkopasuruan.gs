/**
 * ============================================================================
 * GOOGLE APPS SCRIPT ALL-IN-ONE (BISA UNTUK GMAIL & GDRIVE SEKALIGUS)
 * - Mendukung Pembacaan Email "Mofa Enrollment Confirmation" (doGet)
 * - Mendukung Penyimpanan Otomatis ke Google Drive (doPost)
 * ============================================================================
 * CARA UPDATE / DEPLOY ULANG (PENTING!):
 * 1. Buka project Anda di https://script.google.com
 *    - Untuk Gmail  : pastikan login akun phujtmkopasuruan@gmail.com
 *    - Untuk GDrive : pastikan login akun muchammad.ali.sanjaya@gmail.com
 * 2. Hapus seluruh kode lama di editor Code.gs, lalu TEMPEL (Paste) seluruh kode ini.
 * 3. Tekan Ctrl+S (Simpan).
 * 4. PENTING: Agar izin Gmail & Google Drive aktif, pada toolbar atas di sebelah
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
        service: 'Auto-BioVisa All-in-One (Gmail + GDrive Ready)',
        supports_post: true,
        supports_get: true,
        account_email: activeEmail,
        status: 'READY'
      });
    }

    var subjectQuery = params.subject || 'Enrollment confirmation';
    var limit = parseInt(params.limit || '15', 10);
    if (isNaN(limit) || limit < 1) limit = 15;
    if (limit > 30) limit = 30;

    var query = 'subject:"Enrollment confirmation"';
    var threads = GmailApp.search(query, 0, limit);
    var emails = [];

    for (var i = 0; i < threads.length; i++) {
      var messages = threads[i].getMessages();
      for (var j = 0; j < messages.length; j++) {
        var msg = messages[j];
        var subj = msg.getSubject() || '';
        // Lewati email OTP atau "Enrollment under review" agar eksekusi sangat cepat (< 2 detik)
        if (subj.toLowerCase().indexOf('enrollment confirmation') === -1) {
          continue;
        }
        var plainBody = msg.getPlainBody() || '';
        if (plainBody.indexOf('One Time Password') !== -1 || plainBody.indexOf('under review') !== -1) {
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

    if (!base64File) {
      return jsonOut({ ok: false, error: 'Data base64_file kosong.' });
    }

    var folder = getOrCreateTargetFolder(folderName, folderId);

    var existingFiles = folder.getFilesByName(filename);
    while (existingFiles.hasNext()) {
      var oldFile = existingFiles.next();
      oldFile.setTrashed(true);
    }

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
