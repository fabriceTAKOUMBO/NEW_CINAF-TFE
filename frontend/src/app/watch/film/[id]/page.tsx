"use client";

// ============================================================
// CINAF v2 — Page Watch Film (lecteur HLS, alimenté par Bunny)
// ============================================================

import { Suspense } from "react";
import { useParams } from "next/navigation";
import WatchView from "@/components/WatchView";

export default function WatchFilmPage() {
  return (
    <Suspense
      fallback={
        <div className="watch-page d-flex align-items-center justify-content-center" style={{ minHeight: "60vh" }}>
          <div className="spinner-border" style={{ color: "var(--cinaf-gold)" }} />
        </div>
      }
    >
      <WatchFilmContent />
    </Suspense>
  );
}

function WatchFilmContent() {
  const params = useParams();
  const slug = params.id as string;
  return <WatchView slug={slug} context="film" />;
}
