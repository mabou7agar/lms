import type { Metadata } from "next";
import { TrainerProfileClient } from "./trainer-profile-client";

export const metadata: Metadata = {
  title: "Trainer",
  description: "Meet the instructor — background, expertise, and the courses they teach at HElbaron.",
};

export default async function TrainerProfilePage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return <TrainerProfileClient id={id} />;
}
