import { type HilosLegalContext } from "@hilos/core";
import { actions, connection } from "../../../bootstrap/connection.js";
import { scopes } from "../../../bootstrap/session.js";

/** Legal administration bound to this project's connection and page scopes. */
export const hilosLegalContext: HilosLegalContext = {
  connection,
  scopes,
  actions,
};
