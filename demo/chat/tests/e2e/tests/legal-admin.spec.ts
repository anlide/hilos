import { expect, test, type Page } from "@playwright/test";
import { signUpAdmin } from "../helpers/adminGrant.js";
import { expectPageReady, gotoPage, PAGE_REFUSED } from "../helpers/page.js";
import { clickSubmit } from "../helpers/session.js";
import {
  shownByTestId,
  sidewaysOverflow,
} from "../../../../../framework/frontend/e2e/index.js";

test("reads the legal catalog, deviations and revision comparison at desktop and mobile widths", async ({
  page,
}) => {
  await signUpAdmin(page);
  await gotoPage(page, "/hilos/legal");
  await expect(shownByTestId(page, "legal-document-row")).toHaveCount(2);
  await expect(shownByTestId(page, "legal-count-covered")).toHaveText([
    "0",
    "0",
  ]);
  await expect(shownByTestId(page, "legal-count-window")).toHaveText([
    "0",
    "0",
  ]);
  await expect(shownByTestId(page, "legal-count-lapsed")).toHaveText([
    "0",
    "0",
  ]);
  await expect(shownByTestId(page, "legal-check-row")).toHaveCount(4);
  await clickSubmit(
    shownByTestId(page, "legal-document-open").and(
      page.locator('[data-document="terms"]'),
    ),
  );
  await expect(page.getByTestId("legal-set")).toContainText(
    "Hilos standard set 1",
  );
  await expect(page.getByTestId("legal-deviation-row")).toHaveCount(3);
  await expect(shownByTestId(page, "legal-revision-row")).toHaveCount(2);
  await clickSubmit(
    shownByTestId(page, "legal-revision-open").and(
      page.locator('[data-revision="2026-09-27"]'),
    ),
  );
  await expect(page.getByTestId("legal-revision-text")).toBeVisible();
  await expect(page.getByTestId("legal-revision-clause")).toHaveCount(6);
  await expect(
    page.getByTestId("legal-changes-wide").getByTestId("legal-change-row"),
  ).toHaveCount(1);
  await expect(page.getByTestId("legal-revision-accepted")).toHaveText(
    "0 acceptances",
  );

  await page.setViewportSize({ width: 375, height: 812 });
  await expect(page.getByTestId("legal-changes-narrow")).toBeVisible();
  await expect(page.getByTestId("legal-changes-wide")).toBeHidden();
  expect(await sidewaysOverflow(page)).toEqual([0, 0]);
  await gotoPage(page, "/hilos/legal/terms");
  await expect(page.getByTestId("legal-set")).toBeVisible();
  await expect(shownByTestId(page, "legal-revision-row")).toHaveCount(2);
  expect(await sidewaysOverflow(page)).toEqual([0, 0]);
  await gotoPage(page, "/hilos/legal");
  await expect(shownByTestId(page, "legal-document-row")).toHaveCount(2);
  expect(await sidewaysOverflow(page)).toEqual([0, 0]);
});

/** Edits one legal setting through its page, leaving a no-op through Cancel. */
async function setLegalSetting(
  page: Page,
  key: string,
  value: string,
): Promise<void> {
  await gotoPage(page, "/hilos/legal/settings");
  await clickSubmit(shownByTestId(page, `legal-setting-edit-${key}`));
  const input = page.getByTestId("legal-setting-input");
  await expect(input).toBeVisible();
  if ((await input.inputValue()) === value) {
    await clickSubmit(page.getByTestId("legal-setting-cancel"));
  } else {
    await input.selectOption(value);
    await clickSubmit(page.getByTestId("legal-setting-save"));
  }
  await expect(input).toBeHidden();
}

test("offers complete acceptance filters and merges legal setting changes across tabs", async ({
  page,
}) => {
  await signUpAdmin(page);
  await gotoPage(page, "/hilos/legal/acceptances");
  const table = page.getByTestId("legal-acceptances-table");
  await expect(shownByTestId(table, "hilos-table-empty-title")).toHaveText(
    "No acceptance records.",
  );
  const documentFilter = table.getByTestId("hilos-table-filter-document");
  await clickSubmit(documentFilter.getByTestId("hilos-dropdown-toggle"));
  await expect(
    documentFilter.getByTestId("hilos-dropdown-option-0"),
  ).toContainText("Terms");
  await expect(
    documentFilter.getByTestId("hilos-dropdown-option-1"),
  ).toContainText("Privacy policy");
  await clickSubmit(documentFilter.getByTestId("hilos-dropdown-option-0"));
  const revisionFilter = table.getByTestId("hilos-table-filter-revision");
  await clickSubmit(revisionFilter.getByTestId("hilos-dropdown-toggle"));
  await expect(
    revisionFilter.getByTestId("hilos-dropdown-option-0"),
  ).toHaveCount(0);
  await clickSubmit(revisionFilter.getByTestId("hilos-dropdown-toggle"));

  const other = await page.context().newPage();
  try {
    await gotoPage(page, "/hilos/legal/settings");
    await clickSubmit(
      shownByTestId(page, "legal-setting-edit-legal.consent_form"),
    );
    await expect(page.getByTestId("legal-setting-input")).toHaveValue(
      "checkbox",
    );
    await expect(page.getByTestId("legal-setting-save")).toBeDisabled();
    await setLegalSetting(other, "legal.consent_form", "line");
    await expect(page.getByTestId("legal-setting-input")).toHaveValue("line");
    await expect(page.getByTestId("legal-setting-notice")).toContainText(
      "Updated just now",
    );
    await expect(page.getByTestId("legal-setting-save")).toBeDisabled();
    await clickSubmit(page.getByTestId("legal-setting-cancel"));
    await page.reload();
    await expectPageReady(page);
    await expect(
      shownByTestId(page, "legal-setting-value-legal.consent_form"),
    ).toHaveText("Line below the button");
    await setLegalSetting(page, "legal.refusal_after_deadline", "remind");
    await expect(
      shownByTestId(other, "legal-setting-value-legal.refusal_after_deadline"),
    ).toHaveText("Keep reminding");
    await page.setViewportSize({ width: 375, height: 812 });
    await clickSubmit(
      shownByTestId(page, "legal-setting-edit-legal.consent_form"),
    );
    await expect(page.getByTestId("legal-setting-input")).toHaveValue("line");
    await expect(page.getByTestId("legal-setting-save")).toBeDisabled();
    expect(await sidewaysOverflow(page)).toEqual([0, 0]);
    await clickSubmit(page.getByTestId("legal-setting-cancel"));
  } finally {
    const cleanup = await page.context().newPage();
    try {
      await setLegalSetting(cleanup, "legal.consent_form", "checkbox");
      await setLegalSetting(cleanup, "legal.refusal_after_deadline", "freeze");
    } finally {
      await cleanup.close();
      await other.close();
    }
  }
});

test("unknown legal documents and revisions are not-found subscription refusals", async ({
  page,
}) => {
  await signUpAdmin(page);
  for (const path of [
    "/hilos/legal/missing-document",
    "/hilos/legal/terms/missing-revision",
  ]) {
    await gotoPage(page, path, PAGE_REFUSED);
    await expect(page.getByTestId("page-error")).toHaveAttribute(
      "data-error-code",
      "404",
    );
  }
});
