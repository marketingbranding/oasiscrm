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

function doPost(e) {
  const action = e.parameter.action;
  const sheetId = e.parameter.sheet_id;
  const sheetName = e.parameter.sheet_name;

  try {
    if (action === 'addRow') {
      const rowData = JSON.parse(e.parameter.row_data);
      return jsonOutput(addRow(sheetName, sheetId, rowData));
    }
    if (action === 'updateRow') {
      const rowIndex = parseInt(e.parameter.row_index);
      const rowData = JSON.parse(e.parameter.row_data);
      return jsonOutput(updateRow(sheetName, sheetId, rowIndex, rowData));
    }
    if (action === 'deleteRow') {
      const rowIndex = parseInt(e.parameter.row_index);
      return jsonOutput(deleteRow(sheetName, sheetId, rowIndex));
    }
    return jsonOutput({ success: false, error: 'Unknown action: ' + action });
  } catch (err) {
    return jsonOutput({ success: false, error: err.message });
  }
}

function jsonOutput(data) {
  return ContentService
    .createTextOutput(JSON.stringify(data))
    .setMimeType(ContentService.MimeType.JSON);
}

function doGet(e) {
  const type = e.parameter.type;

  // REST: return sheet names + GIDs for a spreadsheet (used by CRM Laravel app)
  if (type === 'sheets') {
    const sheetId = e.parameter.sheet_id;
    if (!sheetId) {
      return ContentService
        .createTextOutput(JSON.stringify({ error: 'sheet_id required' }))
        .setMimeType(ContentService.MimeType.JSON);
    }
    try {
      const ss = SpreadsheetApp.openById(sheetId);
      const sheets = ss.getSheets().map(function(s) {
        return { name: s.getName(), gid: String(s.getSheetId()) };
      });
      return ContentService
        .createTextOutput(JSON.stringify(sheets))
        .setMimeType(ContentService.MimeType.JSON);
    } catch (err) {
      return ContentService
        .createTextOutput(JSON.stringify({ error: err.message }))
        .setMimeType(ContentService.MimeType.JSON);
    }
  }

  // REST: return CSV data for a specific sheet by name (used by CRM Laravel app)
  if (type === 'data') {
    const sheetId = e.parameter.sheet_id;
    const sheetName = e.parameter.sheet_name;
    if (!sheetId || !sheetName) {
      return ContentService
        .createTextOutput('sheet_id and sheet_name required')
        .setMimeType(ContentService.MimeType.TEXT);
    }
    try {
      const ss = SpreadsheetApp.openById(sheetId);
      const sheet = ss.getSheetByName(sheetName);
      if (!sheet) {
        return ContentService
          .createTextOutput('Sheet "' + sheetName + '" not found')
          .setMimeType(ContentService.MimeType.TEXT);
      }
      const data = sheet.getDataRange().getValues();
      const csv = data.map(function(row) {
        return row.map(function(cell) {
          var str;
          if (cell instanceof Date) {
            str = Utilities.formatDate(cell, Session.getScriptTimeZone(), 'yyyy-MM-dd');
          } else {
            str = String(cell);
          }
          if (str.indexOf(',') !== -1 || str.indexOf('"') !== -1 || str.indexOf('\n') !== -1) {
            return '"' + str.replace(/"/g, '""') + '"';
          }
          return str;
        }).join(',');
      }).join('\n');
      return ContentService
        .createTextOutput(csv)
        .setMimeType(ContentService.MimeType.CSV);
    } catch (err) {
      return ContentService
        .createTextOutput('Error: ' + err.message)
        .setMimeType(ContentService.MimeType.TEXT);
    }
  }

  const template = HtmlService.createTemplateFromFile('Index');
  const sheetId = e.parameter.sheet_id || SHEET_IDS[e.parameter.cabang_id] || SHEET_IDS['7'];
  const cabangName = e.parameter.cabang_name || CABANG_NAMES[e.parameter.cabang_id] || 'Database';

  if (!sheetId) {
    return ContentService
      .createTextOutput('Spreadsheet tidak ditemukan.')
      .setMimeType(ContentService.MimeType.TEXT);
  }

  template.sheetId = sheetId;
  template.cabangName = cabangName;
  return template.evaluate()
    .setTitle('OASYNC — ' + cabangName)
    .addMetaTag('viewport', 'width=device-width, initial-scale=1')
    .setXFrameOptionsMode(HtmlService.XFrameOptionsMode.ALLOWALL);
}

function getSheetNames(sheetId) {
  const ss = getSheet(sheetId);
  return ss.getSheets().map(function(s) { return s.getName(); });
}

function getSheetData(sheetName, sheetId) {
  const sheet = getSheetById(sheetId, sheetName);
  if (!sheet) return { headers: [], rows: [], error: 'Sheet "' + sheetName + '" not found' };

  const range = sheet.getDataRange();
  const values = range.getDisplayValues();
  if (values.length === 0) return { headers: [], rows: [], error: null };

  const headers = values[0];
  const rows = [];
  for (var i = 1; i < values.length; i++) {
    var row = {};
    var hasData = false;
    for (var j = 0; j < headers.length; j++) {
      var val = values[i][j];
      row[headers[j]] = (val !== null && val !== undefined) ? String(val) : '';
      if (row[headers[j]] !== '') hasData = true;
    }
    if (hasData) rows.push(row);
  }
  return { headers: headers, rows: rows, error: null };
}

function addRow(sheetName, sheetId, rowData) {
  const lock = LockService.getScriptLock();
  try {
    lock.waitLock(15000);
    const sheet = getSheetById(sheetId, sheetName);
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

function updateRow(sheetName, sheetId, rowIndex, rowData) {
  const lock = LockService.getScriptLock();
  try {
    lock.waitLock(15000);
    const sheet = getSheetById(sheetId, sheetName);
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

function deleteRow(sheetName, sheetId, rowIndex) {
  const lock = LockService.getScriptLock();
  try {
    lock.waitLock(15000);
    const sheet = getSheetById(sheetId, sheetName);
    if (!sheet) return { success: false, error: 'Sheet not found' };

    sheet.deleteRow(rowIndex + 1);
    return { success: true };
  } catch (e) {
    return { success: false, error: e.message };
  } finally {
    lock.releaseLock();
  }
}

// ─── Helpers ────────────────────────────────────────────

function getSheet(sheetId) {
  if (!sheetId) throw new Error('No sheet ID provided');
  return SpreadsheetApp.openById(sheetId);
}

function getSheetById(sheetId, sheetName) {
  const ss = getSheet(sheetId);
  return ss.getSheetByName(sheetName);
}
