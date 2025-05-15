# WebHookARM  
**Author:** Renato Bonomini | **Version:** 1.0 | **License:** GPLv2 or later

## Features

- Hooks into `arm_update_profile_external` from ARMember  
- Sends data as JSON to a Google Apps Script endpoint  
- Supports secure authentication via header or query parameter  
- Compatible with both WordPress and Make.com (formerly Integromat)  
- Fully configurable from the WordPress admin dashboard  

## Installation

1. Upload the plugin folder `WebHookARM` to the `/wp-content/plugins/` directory.  
2. Activate the plugin through the WordPress admin under **Plugins**.  
3. Go to **Settings > ARMember to WebHook** to configure:  
   - Webhook URL (from your Google Apps Script Web App)  
   - Secret Key (must match the script's `AUTH_SECRET`)  
   - Enable/Disable toggle  
4. (Optional) To enable automatic updates from GitHub:
   - Install the [GitHub Updater plugin](https://github.com/afragen/github-updater).
   - This plugin includes the required GitHub header and will notify you when updates are available.

## Google Apps Script Setup

1. Open your target Google Sheet  
2. Go to **Extensions > Apps Script** and paste your script  
3. In **Project Settings**, add Script Properties:  
   - `AUTH_SECRET` – a secure token  
   - `SHEET_NAME` – name of the tab where data should be written  
4. Deploy the script:  
   - **Deploy > Manage Deployments > New Deployment**  
   - Select **Web App**  
   - Set “Execute as”: `Me`  
   - Set “Who has access”: `Anyone`  
5. Copy the Web App URL and paste it into the plugin settings  

## Make.com Support

To use this plugin with Make.com:  
- Use an HTTP module  
- Send a `POST` request to your Apps Script Web App  
- Add `key=YOUR_SECRET` as a query parameter or use a header `X-Security-Key`  
- Set body type to `application/json`  

## Security

Only requests with the correct secret key (header or query param) will be accepted by the Apps Script. Ensure your secret is hard to guess.  

## License

GPLv2 or later

## Updating the Plugin

To receive automatic updates for WebHookARM directly from GitHub, install the [GitHub Updater plugin](https://github.com/afragen/github-updater).
It will check for updates and allow one-click updates from your WordPress dashboard.
