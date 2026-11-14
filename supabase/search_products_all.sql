-- Sirichai chatbot search over the OneElectric product index (oneelectric-chatbot/supabase/schema.sql).
-- Same RRF (k = 60) over a vector leg and a keyword leg as OneElectric's search_products,
-- but sales are ignored: no sales tiebreak and no "sold only" filter. Returns the top 20.
-- Run once in the OneElectric Supabase SQL editor. Does not touch search_products.
-- 20 stays a literal so the planner keeps using the HNSW index (see schema.sql).
select '[1]'::vector; -- loads pgvector so "set hnsw.ef_search" is a known setting
create or replace function search_products_all(query_embedding vector(1536), query_text text)
returns table(product_id bigint, name text)
language sql stable
set hnsw.ef_search = 200
as $$
  with vector_leg as (
    select v.product_id, row_number() over (order by v.distance, v.product_id) as rnk
    from (
      select p.product_id, p.embedding <=> query_embedding as distance
      from products p
      order by p.embedding <=> query_embedding
      limit 20
    ) v
  ),
  tokens as (
    select distinct tok
    from regexp_split_to_table(lower(trim(query_text)), '\s+') as tok
    where tok <> ''
  ),
  keyword_leg as (
    select k.product_id, row_number() over (order by k.score desc, k.product_id) as rnk
    from (
      select p.product_id, count(*) as score
      from products p
      join tokens t on lower(p.name) like '%' || t.tok || '%'
      group by p.product_id
      order by score desc, p.product_id
      limit 20
    ) k
  ),
  fused as (
    select legs.product_id, sum(1.0 / (60 + legs.rnk)) as score
    from (select * from vector_leg union all select * from keyword_leg) legs
    group by legs.product_id
  )
  select p.product_id, p.name
  from fused f
  join products p on p.product_id = f.product_id
  order by f.score desc, p.product_id
  limit 20;
$$;
