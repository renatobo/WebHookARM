/***********************************************
 * Example Google Apps Script for WebHookARM Plugin
 *
 * This script receives authenticated POST requests from WordPress or Make.com
 * and appends ARMember profile update data to a specified Google Sheet.
 *
 * Setup Instructions:
 * 1. Open your target Google Sheet
 * 2. Go to Extensions > Apps Script and paste this code
 * 3. Set the following Script Properties via Project Settings:
 *    - AUTH_SECRET: shared secret key
 *    - SHEET_NAME: name of the tab to write to
 * 4. Deploy the script as a Web App:
 *    - Execute as: Me
 *    - Who has access: Anyone
 *
 * Recommended Sheet Headers (Row 1):
 * | Timestamp | User ID | User Login | User Email | Raw JSON Payload |
 *
 * Example CURL Test:
 * curl -X POST "https://script.google.com/macros/s/YOUR_SCRIPT_ID/exec?action=profile_update&key=YOUR_SECRET" \
 *      -H "Content-Type: application/json" \
 *      -d '{"user_id":123,"user_login":"jdoe","user_email":"jdoe@example.com"}'
 */


function doPost(e) {
  // Logging incoming request
  console.log('Incoming POST request to WebHookARM');
  console.log('Action parameter:', e.parameter.action);
  console.log('Headers:', JSON.stringify(e.headers));
  console.log('Payload:', e.postData && e.postData.contents);

  const action = e?.parameter?.action;
  if (action !== 'profile_update') {
    return ContentService.createTextOutput("Invalid action").setMimeType(ContentService.MimeType.TEXT).setResponseCode(404);
  }
  try {
    const props = PropertiesService.getScriptProperties();
    const AUTH_SECRET = props.getProperty('WA_AUTH_SECRET');
    const SHEET_NAME = props.getProperty('WA_SHEET_NAME');

    const headers = e?.headers || {};
    const params = e?.parameter || {};
    const headerKey = headers['x-security-key'] || headers['X-Security-Key'];
    const queryKey = params['key'];
    const receivedKey = headerKey || queryKey;

    if (receivedKey !== AUTH_SECRET) {
      return ContentService.createTextOutput("Unauthorized").setMimeType(ContentService.MimeType.TEXT);
    }

    const sheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(SHEET_NAME);
    if (!sheet) {
      return ContentService.createTextOutput("Sheet not found").setMimeType(ContentService.MimeType.TEXT);
    }

    let body;
    try {
      body = JSON.parse(e.postData.contents);
    } catch (jsonErr) {
      return ContentService.createTextOutput("Invalid JSON payload").setMimeType(ContentService.MimeType.TEXT);
    }

    // Validate required fields
    if (
      !body ||
      (typeof body.user_id !== 'string' && typeof body.user_id !== 'number') ||
      typeof body.user_login !== 'string' ||
      typeof body.user_email !== 'string'
    ) {
      return ContentService.createTextOutput("Invalid payload structure").setMimeType(ContentService.MimeType.TEXT);
    }

    // Check for unexpected types or executable code patterns (simple heuristic)
    const hasInvalidType = Object.values(body).some(value => {
      const t = typeof value;
      if (t !== 'string' && t !== 'number' && t !== 'boolean' && value !== null) {
        return true;
      }
      if (t === 'string' && (/[\{\}\[\];]/.test(value) && /function|=>/.test(value))) {
        return true;
      }
      return false;
    });
    if (hasInvalidType) {
      return ContentService.createTextOutput("Payload contains invalid data types").setMimeType(ContentService.MimeType.TEXT);
    }

    // Limit JSON string output to 1500 characters for safety
    let rawJson = JSON.stringify(body);
    if (rawJson.length > 1500) {
      rawJson = rawJson.substring(0, 1497) + '...';
    }

    const values = [
      new Date(),
      body.user_id,
      body.user_login,
      body.user_email,
      rawJson
    ];

    sheet.appendRow(values);

    return ContentService.createTextOutput("Success").setMimeType(ContentService.MimeType.TEXT);
  } catch (err) {
    return ContentService.createTextOutput("Error: " + err.message).setMimeType(ContentService.MimeType.TEXT);
  }
}