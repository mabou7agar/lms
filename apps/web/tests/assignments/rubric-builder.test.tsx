import { describe, expect, it } from "vitest";
import { useState } from "react";
import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";
import { RubricBuilder } from "@/components/assignments/builder/rubric-builder";
import {
  criterionMaxPoints,
  rubricTotalPoints,
} from "@/lib/assignments/assignments-format";
import type { RubricInput } from "@/lib/assignments/assignments-api";

/**
 * The rubric builder edits criteria/levels/points/order and shows a DETERMINISTIC total: a
 * criterion is worth the max of its levels, and the rubric total is the sum of those maxima —
 * exactly what the server recomputes on save.
 */

function Harness({ initial }: { initial: RubricInput }) {
  const [value, setValue] = useState<RubricInput>(initial);
  return <RubricBuilder value={value} onChange={setValue} />;
}

const twoCriteria: RubricInput = {
  title: "Project rubric",
  criteria: [
    {
      title: "Clarity",
      description: null,
      levels: [
        { title: "Poor", description: null, points: 3 },
        { title: "Great", description: null, points: 7 },
      ],
    },
    {
      title: "Depth",
      description: null,
      levels: [{ title: "Ok", description: null, points: 5 }],
    },
  ],
};

describe("rubric total (pure)", () => {
  it("criterion points is the max of its levels", () => {
    expect(criterionMaxPoints([{ points: 3 }, { points: 7 }, { points: 1 }])).toBe(7);
    expect(criterionMaxPoints([])).toBe(0);
  });

  it("rubric total sums the per-criterion maxima deterministically", () => {
    expect(rubricTotalPoints(twoCriteria.criteria)).toBe(12); // 7 + 5
    // Order-independent and repeatable.
    const reversed = { ...twoCriteria, criteria: [...twoCriteria.criteria].reverse() };
    expect(rubricTotalPoints(reversed.criteria)).toBe(12);
  });
});

describe("RubricBuilder", () => {
  it("renders the deterministic total for the given criteria", () => {
    renderWithI18n(<Harness initial={twoCriteria} />);
    expect(screen.getByTestId("rubric-total")).toHaveTextContent("12 pts");
  });

  it("shows the empty state and adds a criterion", async () => {
    const user = userEvent.setup();
    renderWithI18n(<Harness initial={{ title: null, criteria: [] }} />);
    expect(screen.getByText(/No criteria yet/i)).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Add criterion" }));
    expect(screen.getByLabelText("Criterion 1")).toBeInTheDocument();
    // A fresh criterion starts with exactly one level.
    expect(within(screen.getByLabelText("Criterion 1")).getAllByLabelText("Level")).toHaveLength(1);
  });

  it("recomputes the total when a level's points change", async () => {
    const user = userEvent.setup();
    renderWithI18n(<Harness initial={twoCriteria} />);
    expect(screen.getByTestId("rubric-total")).toHaveTextContent("12 pts");

    // Raise the top level of the first criterion from 7 to 10 → total 10 + 5 = 15.
    const pointsInputs = screen.getAllByLabelText("Points");
    await user.clear(pointsInputs[1]);
    await user.type(pointsInputs[1], "10");
    expect(screen.getByTestId("rubric-total")).toHaveTextContent("15 pts");
  });

  it("adds and removes levels within a criterion", async () => {
    const user = userEvent.setup();
    renderWithI18n(<Harness initial={twoCriteria} />);
    const firstCriterion = screen.getByLabelText("Criterion 1");

    expect(within(firstCriterion).getAllByLabelText("Level")).toHaveLength(2);
    await user.click(within(firstCriterion).getByRole("button", { name: "Add level" }));
    expect(within(firstCriterion).getAllByLabelText("Level")).toHaveLength(3);

    await user.click(within(firstCriterion).getAllByRole("button", { name: "Remove level" })[0]);
    expect(within(firstCriterion).getAllByLabelText("Level")).toHaveLength(2);
  });

  it("disables removing the last remaining level", () => {
    renderWithI18n(<Harness initial={twoCriteria} />);
    const secondCriterion = screen.getByLabelText("Criterion 2"); // single level
    expect(within(secondCriterion).getByRole("button", { name: "Remove level" })).toBeDisabled();
  });

  it("removes a criterion and updates the total", async () => {
    const user = userEvent.setup();
    renderWithI18n(<Harness initial={twoCriteria} />);
    expect(screen.getByTestId("rubric-total")).toHaveTextContent("12 pts");

    await user.click(
      within(screen.getByLabelText("Criterion 1")).getByRole("button", { name: "Remove criterion" }),
    );
    // Only "Depth" (max 5) remains.
    expect(screen.getByTestId("rubric-total")).toHaveTextContent("5 pts");
    expect(screen.queryByLabelText("Criterion 2")).not.toBeInTheDocument();
  });

  it("reorders criteria with the move controls", async () => {
    const user = userEvent.setup();
    renderWithI18n(<Harness initial={twoCriteria} />);

    // First criterion cannot move up; move it down instead.
    const c1 = screen.getByLabelText("Criterion 1");
    expect(within(c1).getByRole("button", { name: "Move up" })).toBeDisabled();
    await user.click(within(c1).getByRole("button", { name: "Move down" }));

    // After the swap the first criterion's title field now shows "Depth".
    const firstTitle = within(screen.getByLabelText("Criterion 1")).getByLabelText("Criterion");
    expect(firstTitle).toHaveValue("Depth");
  });
});
