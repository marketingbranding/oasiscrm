/**
 * OASISSYNC — Branch Database Manager
 * Standalone Google Apps Script Web App
 *
 * Deploy as: Web App
 * Execute as: Me (groupmarketing.mbn@gmail.com)
 * Who has access: Anyone (parameter-based routing to correct sheet)
 */

const SHEET_IDS = {
  1: '1tUbKZwOQ70nDuFZlYGN0A9hQg9I77_LUldxxYTf8BNA',
  2: '12su1i6R_xHM2mYkO-wwTW8zoSJgoAAGz6Fd_hU8lyhM',
  3: '1YqfRUgXZGX87UxLIrv6Ebg9LBqVyV_Gmy8RZ5ziMwQ0',
  4: '1EqSNyj29bxXd1fLdkH1JmBfzQttTIqTlRFgpiSd6R1M',
  5: '1nqQ4P0NC-pcFtNJvfa93yw-LnB5qFlGVaZP12pJmQR4',
  7: '1Gn1k0L7WWCoD0GsbSQJuRcxxvoUr16MIjFe7Le3rAg4',
  8: '13Lum588xQcU0ySGlwDkbH5zBFqqFbhgS3TTfLWJrAVM',
  11: '1AdsQAaWpOTKl6n5s5djiTKyg04HI2gBdMsib6TsggR8',
};

const CABANG_NAMES = {
  1: 'Malang',
  2: 'Madiun',
  3: 'Solo',
  4: 'Magelang',
  5: 'Purworejo',
  7: 'Jepara',
  8: 'Pekalongan',
  11: 'Bandung',
};

function doGet(e) {
  const cabangId = e.parameter.cabang_id || '7';
  const sheetId = SHEET_IDS[cabangId];

  if (!sheetId) {
    return ContentService
      .createTextOutput('Cabang tidak ditemukan. ID: ' + cabangId)
      .setMimeType(ContentService.MimeType.TEXT);
  }

  const template = HtmlService.createTemplateFromFile('Index');
  template.cabangId = cabangId;
  template.cabangName = CABANG_NAMES[cabangId] || 'Cabang #' + cabangId;
  return template.evaluate()
    .setTitle('OASYNC — ' + (CABANG_NAMES[cabangId] || 'Cabang #' + cabangId))
    .addMetaTag('viewport', 'width=device-width, initial-scale=1')
    .setXFrameOptionsMode(HtmlService.XFrameOptionsMode.ALLOWALL);
}

function getSheetNames(cabangId) {
  const ss = getSheet(cabangId);
  return ss.getSheets().map(function(s) { return s.getName(); });
}

function getSheetData(sheetName, cabangId) {
  const sheet = getSheetById(cabangId, sheetName);
  if (!sheet) return { headers: [], rows: [], error: 'Sheet "' + sheetName + '" not found' };

  const range = sheet.getDataRange();
  const values = range.getValues();
  if (values.length === 0) return { headers: [], rows: [], error: null };

  const headers = values[0].map(function(h) { return String(h); });
  const rows = [];
  for (var i = 1; i < values.length; i++) {
    var row = {};
    var hasData = false;
    for (var j = 0; j < headers.length; j++) {
      var val = values[i][j];
      if (val instanceof Date) {
        row[headers[j]] = Utilities.formatDate(val, Session.getScriptTimeZone(), 'yyyy-MM-dd');
      } else if (val !== null && val !== undefined) {
        row[headers[j]] = String(val);
      } else {
        row[headers[j]] = '';
      }
      if (row[headers[j]] !== '') hasData = true;
    }
    if (hasData) rows.push(row);
  }
  return { headers: headers, rows: rows, error: null };
}

function addRow(sheetName, cabangId, rowData) {
  const lock = LockService.getScriptLock();
  try {
    lock.waitLock(15000);
    const sheet = getSheetById(cabangId, sheetName);
    if (!sheet) return { success: false, error: 'Sheet not found' };

    const headers = sheet.getRange(1, 1, 1, sheet.getLastColumn()).getValues()[0];
    var newRow = [];
    for (var j = 0; j < headers.length; j++) {
      newRow.push(rowData[String(headers[j])] !== undefined ? rowData[String(headers[j])] : '');
    }
    sheet.appendRow(newRow);
    return { success: true };
  } catch (e) {
    return { success: false, error: e.message };
  } finally {
    lock.releaseLock();
  }
}

function updateRow(sheetName, cabangId, rowIndex, rowData) {
  const lock = LockService.getScriptLock();
  try {
    lock.waitLock(15000);
    const sheet = getSheetById(cabangId, sheetName);
    if (!sheet) return { success: false, error: 'Sheet not found' };

    const headers = sheet.getRange(1, 1, 1, sheet.getLastColumn()).getValues()[0];
    var updateRow = [];
    for (var j = 0; j < headers.length; j++) {
      updateRow.push(rowData[String(headers[j])] !== undefined ? rowData[String(headers[j])] : '');
    }
    sheet.getRange(rowIndex + 1, 1, 1, updateRow.length).setValues([updateRow]);
    return { success: true };
  } catch (e) {
    return { success: false, error: e.message };
  } finally {
    lock.releaseLock();
  }
}

function deleteRow(sheetName, cabangId, rowIndex) {
  const lock = LockService.getScriptLock();
  try {
    lock.waitLock(15000);
    const sheet = getSheetById(cabangId, sheetName);
    if (!sheet) return { success: false, error: 'Sheet not found' };

    sheet.deleteRow(rowIndex + 1);
    return { success: true };
  } catch (e) {
    return { success: false, error: e.message };
  } finally {
    lock.releaseLock();
  }
}

function getCabangInfo(cabangId) {
  return {
    id: cabangId,
    name: CABANG_NAMES[cabangId] || 'Cabang #' + cabangId,
    hasSheet: !!SHEET_IDS[cabangId],
  };
}

// ─── Helpers ────────────────────────────────────────────

function getSheet(cabangId) {
  const sheetId = SHEET_IDS[cabangId];
  if (!sheetId) throw new Error('No sheet ID for cabang ' + cabangId);
  return SpreadsheetApp.openById(sheetId);
}

function getSheetById(cabangId, sheetName) {
  const ss = getSheet(cabangId);
  return ss.getSheetByName(sheetName);
}
