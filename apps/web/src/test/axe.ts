import axe from 'axe-core';

/** Runs axe against a container and returns violations (colour contrast is verified on tokens instead; jsdom has no layout). */
export async function axeViolations(container: Element) {
  const results = await axe.run(container, { rules: { 'color-contrast': { enabled: false } } });
  return results.violations.map((v) => `${v.id}: ${v.help} (${v.nodes.length})`);
}
