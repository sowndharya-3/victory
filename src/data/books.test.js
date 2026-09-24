import { seedBooks } from './books'

test('every seed book has required fields', () => {
  expect(seedBooks.length).toBeGreaterThan(0)
  for (const b of seedBooks) {
    expect(b).toMatchObject({
      id: expect.any(String),
      title: expect.any(String),
      type: expect.any(String),
      onlinePrice: expect.any(Number),
      hardCopyPrice: expect.any(Number),
      published: expect.any(Boolean),
    })
    expect(Array.isArray(b.pageImages)).toBe(true)
  }
})
