import { expect, test } from "@playwright/test";

import { dismissToasts } from "../../../../../framework/frontend/e2e/index.js";
import { signUpAdmin } from "../helpers/adminGrant";
import { gotoPage } from "../helpers/page";
import { clickSubmit, login, PASSWORD, signUp } from "../helpers/session";

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
