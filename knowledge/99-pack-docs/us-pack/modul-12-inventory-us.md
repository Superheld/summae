# Modul 12 — Inventory categories US (`inventory`)

```
kind: inventory · id: us-inventory-accounts · version: 2026.1 · formatVersion: 0.6
contributes: ["inventory"] · dependsOn: [{ kind: accounts, id: us-accounts-2026 }]
data.categories[] = { account, changeAccount, label }
```

## Purpose

Which accounts of the US chart hold inventory, and **where a change in inventory is booked**.
Read by `valuateInventory` (F-CORE-050): the core computes the carrying value, forms the difference
against the current book value and posts it — to the inventory account and to the account named
here.

## Categories

| Inventory account | `changeAccount` | |
|---|---|---|
| `1300` Raw Materials | `5000` Cost of Goods Sold | |
| `1310` Work in Process | `5000` Cost of Goods Sold | |
| `1320` Finished Goods | `5000` Cost of Goods Sold | |
| `1330` Merchandise | `5000` Cost of Goods Sold | |

## The contrast with the `de` pack is the point of shipping both

The German module (`de-vorraete`) names **two different** counter-accounts: a change in finished and
unfinished goods is its own income-statement line (§ 275 Abs. 2 Nr. 2 HGB), while a change in raw
materials and merchandise corrects material expense (Nr. 5), because consumption is purchases minus
the build-up. A US income statement has no such line at all — every change in inventory adjusts cost
of goods sold, so all four rows name the same account.

Two packs, the same engine, the same operation, the same books, two statements — and the **whole**
of the difference is these four rows. That is the litmus test of this project in its shortest form:
if the core had branched on "is this finished goods?", it would have been German law compiled into
a jurisdiction-free substrate.

## What the core still knows by itself

- That an inventory account must carry `subtype: "inventory"` (`E_INVENTORY_ACCOUNT_INVALID`).
- That the **difference** is posted, not the balance — so an unchanged period posts nothing.
- That the lower of cost and a supplied market value applies, and that the row says which was taken.
  Whether the comparison is required (US GAAP: lower of cost and net realizable value, ASC 330) is
  not in the core; the core takes the smaller of two numbers.

## What is deliberately absent

No stock ledger, no product master, no goods movements. Quantities are **input** to one act of
valuing. The consequence is stated rather than hidden: a **consumption sequence** (FIFO, and the
LIFO that ASC 330 still permits) needs the history of entry values, and summae keeps none. It is
row 6 of the open list in `docs/hgb-conformance.md` and will not be built quietly.
