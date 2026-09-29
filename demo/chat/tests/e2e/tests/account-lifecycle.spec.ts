import { expect, test } from "@playwright/test";

import {
  dismissToasts,
  shownByTestId,
} from "../../../../../framework/frontend/e2e/index.js";
import { signUpAdmin } from "../helpers/adminGrant";
import { gotoPage } from "../helpers/page";
import {
  clickSubmit,
  login,
  PASSWORD,
  signUp,
  typeInto,
} from "../helpers/session";

// Two independent browsers prove the card's writes reach the affected person.
test("blocks an account from its card and restores sign-in when the block is lifted", async ({
  browser,
  page,
}) => {
  const { baseURL, ignoreHTTPSErrors } = test.info().project.use;
  const personContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors,
  });
  const personPage = await personContext.newPage();
  try {
    await signUpAdmin(page);
    const person = await signUp(personPage);
    await gotoPage(page, `/hilos/user/${person.userId}`);
    await clickSubmit(page.getByTestId("hilos-user-block-open"));
    // Blocking is declared off in the list of confirmed operations (HIL-1275): the
    // window opens straight on its own text.
    await expect(page.getByTestId("modal")).toBeVisible();
    await expect(page.getByTestId("step-up")).toHaveCount(0);
    await clickSubmit(page.getByTestId("hilos-user-lifecycle-confirm"));
    await expect(page.getByTestId("modal")).toBeHidden();
    await expect(page.getByTestId("hilos-toast-success")).toContainText(
      "Account blocked. Sessions ended: 1",
    );
    await expect(personPage.getByTestId("account-blocked")).toBeVisible();
    await expect(personPage.getByTestId("account-blocked-heading")).toHaveText(
      "Access closed",
    );
    await expect(page.getByTestId("hilos-user-block-open")).toHaveText(
      "Lift the block",
    );

    await dismissToasts(page);
    await clickSubmit(page.getByTestId("hilos-user-block-open"));
    await clickSubmit(page.getByTestId("hilos-user-lifecycle-confirm"));
    await expect(page.getByTestId("modal")).toBeHidden();
    await expect(personPage.getByTestId("account-blocked")).toHaveCount(0);
    await expect(personPage.getByTestId("message-signin")).toBeVisible();
    await clickSubmit(personPage.getByTestId("message-signin"));
    await login(personPage, person.email, PASSWORD);
    await expect(personPage.getByTestId("self-user-id")).toHaveText(
      String(person.userId),
    );
  } finally {
    await personContext.close();
  }
});

test("schedules and cancels deletion on the card while the person sees it in the profile", async ({
  browser,
  page,
}) => {
  const { baseURL, ignoreHTTPSErrors } = test.info().project.use;
  const personContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors,
  });
  const personPage = await personContext.newPage();
  try {
    await signUpAdmin(page);
    const person = await signUp(personPage);
    await gotoPage(personPage, "/profile");
    await expect(personPage.getByTestId("account-deletion-open")).toBeVisible();
    await gotoPage(page, `/hilos/user/${person.userId}`);
    await clickSubmit(page.getByTestId("hilos-user-deletion-open"));
    // Scheduling someone else's deletion asks the administrator's own password
    // first (HIL-1275); calling it off below asks nothing.
    await typeInto(page.getByTestId("step-up-password"), PASSWORD);
    await clickSubmit(page.getByTestId("hilos-user-lifecycle-step-up-confirm"));
    await expect(page.getByTestId("modal")).toContainText("After 30 days");
    await clickSubmit(page.getByTestId("hilos-user-lifecycle-confirm"));
    await expect(page.getByTestId("modal")).toBeHidden();
    await expect(page.getByTestId("hilos-user-deletion-state")).toContainText(
      "Erased on",
    );
    await expect(page.getByTestId("hilos-user-deletion-state")).toContainText(
      "30 days left",
    );
    await expect(
      personPage.getByTestId("account-deletion-scheduled"),
    ).toBeVisible();
    await expect(page.getByTestId("hilos-user-deletion-open")).toHaveText(
      "Cancel deletion",
    );

    await dismissToasts(page);
    await clickSubmit(page.getByTestId("hilos-user-deletion-open"));
    await expect(page.getByTestId("modal")).toBeVisible();
    await expect(page.getByTestId("step-up")).toHaveCount(0);
    await clickSubmit(page.getByTestId("hilos-user-lifecycle-confirm"));
    await expect(page.getByTestId("modal")).toBeHidden();
    await expect(page.getByTestId("hilos-user-deletion-state")).toContainText(
      "No deletion is scheduled",
    );
    await expect(
      personPage.getByTestId("account-deletion-scheduled"),
    ).toHaveCount(0);
    await expect(personPage.getByTestId("account-deletion-open")).toBeVisible();
  } finally {
    await personContext.close();
  }
});

// The rights window is the one these two share, and the second switches its
// removal on for the whole stand: they run in order, never beside each other.
test.describe("admin rights and their confirmation", () => {
  test.describe.configure({ mode: "serial" });

  // Granting rights is the administrator's to confirm (HIL-1275), and the
  // confirmation lives on the operation in this browser: the second grant within
  // its lifetime opens straight on its own text.
  test("grants admin rights after the administrator confirms it is them", async ({
    browser,
    page,
  }) => {
    const { baseURL, ignoreHTTPSErrors } = test.info().project.use;
    const personContext = await browser.newContext({
      baseURL,
      ignoreHTTPSErrors,
    });
    const personPage = await personContext.newPage();
    try {
      await signUpAdmin(page);
      const person = await signUp(personPage);
      await gotoPage(page, `/hilos/user/${person.userId}`);
      await clickSubmit(page.getByTestId("hilos-user-admin-open"));
      await expect(page.getByTestId("modal")).toContainText("Confirm it's you");
      await typeInto(page.getByTestId("step-up-password"), PASSWORD);
      await clickSubmit(page.getByTestId("hilos-user-lifecycle-step-up-confirm"));
      await clickSubmit(page.getByTestId("hilos-user-lifecycle-confirm"));
      await expect(page.getByTestId("modal")).toBeHidden();
      await expect(page.getByTestId("hilos-toast-success")).toContainText(
        "Admin rights granted",
      );

      // Removing rights is declared off: no step.
      await dismissToasts(page);
      await clickSubmit(page.getByTestId("hilos-user-admin-open"));
      await expect(page.getByTestId("modal")).toBeVisible();
      await expect(page.getByTestId("step-up")).toHaveCount(0);
      await clickSubmit(page.getByTestId("hilos-user-lifecycle-confirm"));
      await expect(page.getByTestId("modal")).toBeHidden();

      await dismissToasts(page);
      await clickSubmit(page.getByTestId("hilos-user-admin-open"));
      await expect(page.getByTestId("modal")).toBeVisible();
      await expect(page.getByTestId("step-up")).toHaveCount(0);
      await clickSubmit(page.getByTestId("hilos-user-lifecycle-confirm"));
      await expect(page.getByTestId("hilos-toast-success")).toContainText(
        "Admin rights granted",
      );
    } finally {
      await personContext.close();
    }
  });

  // An operation declared off asks once an administrator switches it on
  // (HIL-1275). Removing rights is switched here rather than blocking: no spec
  // outside this group opens that window, and the group runs in order, so the
  // switch cannot reach a test running beside it. It is switched back off
  // whatever happens.
  test("asks before removing rights once the administrator switches it on", async ({
    browser,
    page,
  }) => {
    const { baseURL, ignoreHTTPSErrors } = test.info().project.use;
    const personContext = await browser.newContext({
      baseURL,
      ignoreHTTPSErrors,
    });
    const personPage = await personContext.newPage();
    const revokeSwitch = shownByTestId(
      page,
      "hilos-step-up-switch-revoke_admin",
    );
    let switched = false;
    try {
      await signUpAdmin(page);
      const person = await signUp(personPage);
      await gotoPage(page, "/hilos/security/2fa");
      await expect(revokeSwitch).not.toBeChecked();
      await revokeSwitch.click();
      switched = true;
      await expect(revokeSwitch).toBeChecked();

      await gotoPage(page, `/hilos/user/${person.userId}`);
      await clickSubmit(page.getByTestId("hilos-user-admin-open"));
      await typeInto(page.getByTestId("step-up-password"), PASSWORD);
      await clickSubmit(page.getByTestId("hilos-user-lifecycle-step-up-confirm"));
      await clickSubmit(page.getByTestId("hilos-user-lifecycle-confirm"));
      await expect(page.getByTestId("modal")).toBeHidden();

      await dismissToasts(page);
      await clickSubmit(page.getByTestId("hilos-user-admin-open"));
      await expect(page.getByTestId("modal")).toContainText("Confirm it's you");
      await typeInto(page.getByTestId("step-up-password"), PASSWORD);
      await clickSubmit(page.getByTestId("hilos-user-lifecycle-step-up-confirm"));
      await clickSubmit(page.getByTestId("hilos-user-lifecycle-confirm"));
      await expect(page.getByTestId("hilos-toast-success")).toContainText(
        "Admin rights removed",
      );
    } finally {
      if (switched) {
        await gotoPage(page, "/hilos/security/2fa");
        await revokeSwitch.click();
        await expect(revokeSwitch).not.toBeChecked();
      }
      await personContext.close();
    }
  });
});
