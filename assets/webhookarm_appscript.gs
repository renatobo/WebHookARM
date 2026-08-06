/**
 * Hardened Google Apps Script receiver for WebHookARM.
 *
 * Script properties required:
 * - WA_AUTH_SECRET: the same strong secret configured in WordPress
 * - WA_SHEET_NAME: destination sheet tab name
 *
 * Recommended columns:
 * Timestamp | Delivery ID | User ID | User Login | User Email | Raw JSON Payload
 */

const WEBHOOKARM_MAX_BODY_BYTES = 262144;
const WEBHOOKARM_MAX_CLOCK_SKEW_SECONDS = 300;

function doPost(e) {
  try {
    const request = parseAndAuthenticateRequest(e);
    const sheet = getDestinationSheet();
    const values = createSafeRow(request.body, request.deliveryId);
    const lock = LockService.getScriptLock();
    const cache = CacheService.getScriptCache();
    const cacheKey = 'delivery_' + request.deliveryId;

    lock.waitLock(10000);
    try {
      if (cache.get(cacheKey)) {
        return textResponse('Already processed');
      }
      sheet.appendRow(values);
      cache.put(cacheKey, '1', 21600);
    } finally {
      lock.releaseLock();
    }

    return textResponse('Success');
  } catch (error) {
    // Apps Script web apps do not allow ContentService callers to select an
    // HTTP status code. Keep public responses generic and avoid logging PII.
    console.warn('WebHookARM request rejected: ' + safeErrorCategory(error));
    return textResponse('Request rejected');
  }
}

function parseAndAuthenticateRequest(e) {
  if (!e || !e.parameter || e.parameter.action !== 'profile_update') {
    throw new Error('invalid_action');
  }

  const rawBody = e.postData && typeof e.postData.contents === 'string'
    ? e.postData.contents
    : '';

  if (!rawBody || rawBody.length > WEBHOOKARM_MAX_BODY_BYTES) {
    throw new Error('invalid_body_size');
  }

  const timestamp = Number(e.parameter.timestamp);
  const now = Math.floor(Date.now() / 1000);
  if (!Number.isInteger(timestamp) || Math.abs(now - timestamp) > WEBHOOKARM_MAX_CLOCK_SKEW_SECONDS) {
    throw new Error('expired_request');
  }

  // WordPress always sends a lowercase UUID; normalize so the signed string matches.
  const deliveryId = String(e.parameter.delivery || '').toLowerCase();
  if (!/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(deliveryId)) {
    throw new Error('invalid_delivery');
  }

  const secret = PropertiesService.getScriptProperties().getProperty('WA_AUTH_SECRET');
  if (!secret || secret.length < 16) {
    throw new Error('receiver_not_configured');
  }

  // The delivery id is part of the signed string, so it cannot be swapped to
  // bypass the idempotency cache in doPost().
  const expectedSignature = toHex(
    Utilities.computeHmacSha256Signature(deliveryId + '.' + String(timestamp) + '.' + rawBody, secret)
  );
  const receivedSignature = String(e.parameter.signature || '').toLowerCase();

  if (!constantTimeEqual(receivedSignature, expectedSignature)) {
    throw new Error('invalid_signature');
  }

  let body;
  try {
    body = JSON.parse(rawBody);
  } catch (error) {
    throw new Error('invalid_json');
  }

  if (
    !body ||
    (typeof body.user_id !== 'string' && typeof body.user_id !== 'number') ||
    typeof body.user_login !== 'string' ||
    typeof body.user_email !== 'string'
  ) {
    throw new Error('invalid_payload');
  }

  return { body: body, deliveryId: deliveryId };
}

function getDestinationSheet() {
  const sheetName = PropertiesService.getScriptProperties().getProperty('WA_SHEET_NAME');
  if (!sheetName) {
    throw new Error('receiver_not_configured');
  }

  const sheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(sheetName);
  if (!sheet) {
    throw new Error('sheet_not_found');
  }

  return sheet;
}

function createSafeRow(body, deliveryId) {
  return [
    new Date(),
    deliveryId,
    Number(body.user_id),
    neutralizeFormula(body.user_login),
    neutralizeFormula(body.user_email),
    neutralizeFormula(JSON.stringify(body))
  ];
}

function neutralizeFormula(value) {
  const text = String(value == null ? '' : value);
  return /^[=+\-@]/.test(text) ? "'" + text : text;
}

function constantTimeEqual(left, right) {
  if (left.length !== right.length) {
    return false;
  }

  let difference = 0;
  for (let index = 0; index < left.length; index += 1) {
    difference |= left.charCodeAt(index) ^ right.charCodeAt(index);
  }
  return difference === 0;
}

function toHex(bytes) {
  return bytes.map(function (byte) {
    const unsigned = byte < 0 ? byte + 256 : byte;
    return unsigned.toString(16).padStart(2, '0');
  }).join('');
}

function safeErrorCategory(error) {
  const allowed = [
    'invalid_action',
    'invalid_body_size',
    'expired_request',
    'receiver_not_configured',
    'invalid_signature',
    'invalid_json',
    'invalid_payload',
    'invalid_delivery',
    'sheet_not_found'
  ];
  return error && allowed.indexOf(error.message) !== -1 ? error.message : 'internal_error';
}

function textResponse(message) {
  return ContentService.createTextOutput(message).setMimeType(ContentService.MimeType.TEXT);
}
