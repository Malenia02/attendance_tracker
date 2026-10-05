# Testing the installable attendance app

The `testing` branch adds a web app manifest and home-screen icons. It does not
add offline attendance, a service worker, or any API response caching. Every
time entry still needs the online Laravel API.

1. Deploy the `testing` branch to a Vercel Preview URL. Before entering any
   attendance, verify that Preview uses the intended test backend and database;
   a preview deployment can otherwise write real records.
2. On Android, open the Preview URL in Chrome and choose **Install app** from
   the browser menu. On iPhone, open it in Safari and choose **Share → Add to
   Home Screen**.
3. Launch the installed icon and confirm it opens without a browser address bar.
4. Test sign-in, the optional 15-day remembered session, logout, QR camera
   permission, and location permission using test accounts.
5. Turn off the phone's connection and confirm that attendance cannot be
   submitted offline. Reconnect and confirm that the app recovers normally.
6. Test a later Preview deployment to confirm the installed app loads the new
   frontend. If permissions or login differ from browser mode, record the phone
   model, OS, browser, and exact error before merging into `main`.

The installed Preview app belongs to its Preview URL. A later production
installation is separate and should be tested on the production URL after the
branch is approved.
