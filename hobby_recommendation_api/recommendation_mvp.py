import argparse
import json
import math
import os
from typing import Dict
import numpy as np
import pandas as pd

CATALOG_PATH = "hobby_catalog_full_100_service.xlsx"

USER_KEYS = [
    "E_introvert","O_openness","C_conscientiousness","A_autonomy","K_competence",
    "Energy_dynamic","Extrinsic_drive","Online_affinity","Breadth_preference","Process_focus"
]

SURVEY_TO_KEY = {
    "Q6":  "E_introvert",
    "Q7":  "O_openness",
    "Q8":  "C_conscientiousness",
    "Q9":  "A_autonomy",
    "Q10": "K_competence",
    "Q11": "Energy_dynamic",
    "Q12": "Extrinsic_drive",
    "Q13": "Online_affinity",
    "Q14": "Breadth_preference",
    "Q15": "Process_focus"
}

# Feature mapping between user vector and hobby attributes
HOBBY_KEYS = {
    "E_introvert": None,  # handled via social_mode bonus
    "O_openness": "openness_alignment",
    "C_conscientiousness": "conscientiousness_alignment",
    "A_autonomy": "autonomy_alignment",
    "K_competence": "competence_alignment",
    "Energy_dynamic": "activity_energy",
    "Extrinsic_drive": None,   # handled via monetizable bonus
    "Online_affinity": None,   # handled via location/online bonus
    "Breadth_preference": "commitment_depth",  # inverse later if needed
    "Process_focus": None      # handled via explanation / minor weight
}

# Weights for the similarity score
W = {
    "O_openness": 1.0,
    "C_conscientiousness": 1.2,
    "A_autonomy": 1.0,
    "K_competence": 1.4,
    "Energy_dynamic": 1.1,
    "Breadth_preference": 0.8  # note: this compares to commitment_depth
}

def normalize_likert(x: int) -> float:
    x = int(x)
    if not (1 <= x <= 5):
        raise ValueError("Likert must be 1..5")
    return (x - 1) / 4.0

def build_user_vector(survey: Dict[str, int]) -> Dict[str, float]:
    """
    필수 문항(Q6~Q15)이 일부 누락되더라도 중립값(0.5)으로 보완하여 안정적으로 벡터를 만듭니다.
    """
    z: Dict[str, float] = {}
    for q, key in SURVEY_TO_KEY.items():
        if q in survey:
            z[key] = normalize_likert(survey[q])
        else:
            # 중립값으로 보완
            z[key] = 0.5
    return z

def load_catalog(path: str = CATALOG_PATH) -> pd.DataFrame:
    """
    CSV 우선, 없으면 엑셀 시트 'catalog_full' 로딩.
    주요 컬럼의 NaN/타입을 안전하게 보정합니다.
    """
    csv_try = path.replace(".xlsx", ".xlsx - catalog_full.csv")
    if os.path.exists(csv_try):
        df = pd.read_csv(csv_try)
    elif os.path.exists(path):
        df = pd.read_excel(path, sheet_name="catalog_full")
    else:
        raise FileNotFoundError(f"Catalog not found: {csv_try} or {path}")

    # 불리언/카테고리/수치형 컬럼 보정
    bool_cols = ["needs_offline", "monetizable", "online_available"]
    for c in bool_cols:
        if c in df.columns:
            df[c] = df[c].fillna(False).astype(bool)

    cat_cols = ["social_mode", "location_type", "tags", "name_ko", "short_desc"]
    for c in cat_cols:
        if c in df.columns:
            df[c] = df[c].fillna("")

    num_cols = [
        "openness_alignment", "conscientiousness_alignment", "autonomy_alignment",
        "competence_alignment", "activity_energy", "commitment_depth",
        "avg_cost_month_krw", "avg_session_time_hours"
    ]
    for c in num_cols:
        if c in df.columns:
            df[c] = pd.to_numeric(df[c], errors="coerce").fillna(0.0)

    return df

def _bool_safe(x) -> bool:
    """NaN/None/'' → False, 문자열 'true'/'1' 등은 True."""
    if pd.isna(x):
        return False
    if isinstance(x, (int, np.integer)):
        return x != 0
    if isinstance(x, str):
        return x.strip().lower() in {"1", "true", "yes", "y", "t"}
    return bool(x)

def weighted_cosine(user: Dict[str, float], row: pd.Series) -> float:
    num = du = dh = 0.0
    for ukey, hkey in HOBBY_KEYS.items():
        if hkey is None:
            continue
        w = W.get(ukey, 1.0)
        uval = float(user.get(ukey, 0.0))
        hval = float(row.get(hkey, 0.0) or 0.0)
        # Breadth vs depth 보정
        if ukey == "Breadth_preference":
            hval = 1.0 - hval
        if np.isnan(hval):
            hval = 0.0
        num += w * uval * hval
        du  += w * (uval ** 2)
        dh  += w * (hval ** 2)
    if du <= 0 or dh <= 0:
        return 0.0
    return num / (math.sqrt(du) * math.sqrt(dh) + 1e-8)

def bonuses(user: Dict[str, float], row: pd.Series) -> float:
    b = 0.0
    social = str(row.get("social_mode", "")).lower()
    intro = float(user.get("E_introvert", 0.5))

    # Sociality
    if intro >= 0.6 and social == "solo":
        b += 0.08
    if intro < 0.4 and social in {"parallel", "community"}:
        b += 0.06

    # Monetization
    if float(user.get("Extrinsic_drive", 0.0)) >= 0.6 and _bool_safe(row.get("monetizable", False)):
        b += 0.07

    # Online affinity
    loc = str(row.get("location_type", "")).lower()
    online_ok = (loc in {"online", "any"}) or _bool_safe(row.get("online_available", False))
    if float(user.get("Online_affinity", 0.0)) >= 0.6 and online_ok:
        b += 0.05

    # Healthy dopamine bias
    if (float(user.get("K_competence", 0.0)) >= 0.6 or float(user.get("C_conscientiousness", 0.0)) >= 0.6) \
       and float(row.get("activity_energy", 0.0)) >= 0.6:
        b += 0.05

    return b

def explain_reason(user: Dict[str, float], row: pd.Series, base: float, total: float) -> str:
    parts = []
    drivers = []
    drivers.append(("성장/숙련(유능성)", user.get("K_competence"), row.get("competence_alignment", 0)))
    drivers.append(("계획/꾸준함(성실성)", user.get("C_conscientiousness"), row.get("conscientiousness_alignment", 0)))
    drivers.append(("새로운 경험(개방성)", user.get("O_openness"), row.get("openness_alignment", 0)))
    drivers.append(("자율성/창작", user.get("A_autonomy"), row.get("autonomy_alignment", 0)))
    drivers.append(("활동 에너지", user.get("Energy_dynamic"), row.get("activity_energy", 0)))

    drivers = [(name, float(u) * float(h)) for name, u, h in drivers if u is not None and h is not None]
    drivers.sort(key=lambda x: x[1], reverse=True)

    for name, score in drivers[:3]:
        if score > 0.1:
            parts.append(name)

    social = str(row.get("social_mode", "")).lower()
    if user.get("E_introvert", 0.5) >= 0.6 and social == "solo":
        parts.append("혼자 몰입")
    if user.get("E_introvert", 0.5) < 0.4 and social in {"parallel", "community"}:
        parts.append("함께하는 활동")
    if user.get("Extrinsic_drive", 0.0) >= 0.6 and _bool_safe(row.get("monetizable")):
        parts.append("수익화 가능")

    return " · ".join(parts) if parts else "사용자 성향과 전반적으로 잘 맞습니다."

def recommend(
    survey: Dict[str, int],
    user_ctx: Dict,
    top_k: int = 10,
    catalog_path: str = CATALOG_PATH
) -> pd.DataFrame:
    """
    사용자 설문과 컨텍스트를 기반으로 취미를 추천합니다.
    벡터 연산 안정성(NaN/불리언/카테고리)과 파일 로딩 안전성을 강화했습니다.
    """
    user = build_user_vector(survey)
    df = load_catalog(catalog_path)

    # 1) 필터링 (NaN 안전)
    filtered_df = df.copy()
    if "monthly_budget" in user_ctx:
        mb = user_ctx["monthly_budget"]
        if "avg_cost_month_krw" in filtered_df.columns:
            filtered_df = filtered_df[~(filtered_df["avg_cost_month_krw"] > mb)]
    if "session_time_limit_hours" in user_ctx:
        tl = user_ctx["session_time_limit_hours"]
        if "avg_session_time_hours" in filtered_df.columns:
            filtered_df = filtered_df[~(filtered_df["avg_session_time_hours"] > tl)]
    # offline_ok가 명시적으로 False일 때만 오프라인 활동 제외 (기본은 허용)
    if user_ctx.get("offline_ok", True) is False and "needs_offline" in filtered_df.columns:
        filtered_df = filtered_df[~filtered_df["needs_offline"].fillna(False)]

    if filtered_df.empty:
        return pd.DataFrame()

    # 2) 점수 계산
    scores = filtered_df.apply(
        lambda row: pd.Series({
            "score_base": weighted_cosine(user, row),
            "score_bonus": bonuses(user, row)
        }),
        axis=1
    )
    filtered_df["score_base"] = scores["score_base"].fillna(0.0)
    filtered_df["score_total_raw"] = filtered_df["score_base"] + scores["score_bonus"].fillna(0.0)

    # 3) 상위 K 선택 및 정규화(0~100)
    top = filtered_df.nlargest(top_k, "score_total_raw").copy()
    if top.empty:
        return pd.DataFrame()

    max_score = top["score_total_raw"].max()
    top["score_total"] = (top["score_total_raw"] / max_score * 100).round(2) if max_score > 0 else 0.0

    top["rank"] = range(1, len(top) + 1)

    # 4) 추천 이유
    top["reason"] = top.apply(
        lambda row: explain_reason(user, row, row["score_base"], row["score_total_raw"]),
        axis=1
    )

    # 원래 소비 코드와 호환되는 컬럼 셋 유지
    cols = [
        "rank","hobby_id","name_ko","short_desc",
        "avg_cost_month_krw","avg_session_time_hours",
        "social_mode","location_type","tags",
        "score_total","score_base","reason"
    ]
    # 혹시 일부 컬럼이 없을 수 있으므로 존재하는 것만 반환
    cols = [c for c in cols if c in top.columns]
    return top[cols]

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--survey_json", type=str, required=False,
                        help='JSON like {"Q6":4,"Q7":5,...,"Q15":3}')
    parser.add_argument("--budget", type=int, default=100000)
    parser.add_argument("--time_limit", type=float, default=3.0)
    # 기본값은 제공하지 않아 offline_ok 키 자체를 생략 → 온라인/오프라인 모두 허용
    parser.add_argument("--offline_ok", action='store_true',
                        help="Set this flag if offline activities are okay.")
    parser.add_argument("--topk", type=int, default=10)
    parser.add_argument("--catalog", type=str, default=CATALOG_PATH)
    parser.add_argument("--out_csv", type=str, default="sample_output_top10.csv")
    args = parser.parse_args()

    if args.survey_json:
        survey = json.loads(args.survey_json)
    else:
        # sample default
        survey = {"Q6":4,"Q7":5,"Q8":4,"Q9":4,"Q10":5,"Q11":3,"Q12":2,"Q13":4,"Q14":3,"Q15":3}

    # offline_ok는 True일 때만 명시적으로 전달(기본은 허용 로직)
    user_ctx = {
        "monthly_budget": args.budget,
        "session_time_limit_hours": args.time_limit,
    }
    if args.offline_ok:
        user_ctx["offline_ok"] = True  # 명시적 허용

    top = recommend(survey, user_ctx, top_k=args.topk, catalog_path=args.catalog)
    # 결과 저장 및 출력
    if not top.empty:
        top.to_csv(args.out_csv, index=False, encoding="utf-8-sig")
        print(top.to_string(index=False))
    else:
        print("조건에 맞는 추천 결과가 없습니다.")

if __name__ == "__main__":
    main()
