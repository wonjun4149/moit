# main_V3.py (main_tea.py 기반 + ReAct 취미 추천 에이전트 이식)

# --- 1. 기본 라이브러리 import ---
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
import os
from dotenv import load_dotenv
import requests
import json
from typing import List, TypedDict, Optional
import logging
from fastapi.middleware.cors import CORSMiddleware

# --- 2. 로깅 기본 설정 ---
logging.basicConfig(level=logging.INFO, format='%(levelname)s:     %(message)s')

# --- 3. LangChain, LangGraph 및 AI 관련 라이브러리 import ---
from langchain_openai import ChatOpenAI, OpenAIEmbeddings
from langchain_core.prompts import ChatPromptTemplate, MessagesPlaceholder
from langchain_core.output_parsers import StrOutputParser
from langchain_pinecone import PineconeVectorStore
from langgraph.prebuilt import create_react_agent # ReAct Agent
from langgraph.graph import StateGraph, END
from langchain_core.tools import tool
import google.generativeai as genai # Gemini 추가
from langchain_core.documents import Document

# --- 4. 환경 설정 및 FastAPI 앱 초기화 ---
load_dotenv()
app = FastAPI(
    title="MOIT AI Agent Server v3 (ReAct 이식)",
    description="main_tea.py 기반 위에 ReAct 취미 추천 에이전트를 이식한 버전",
    version="3.0.0",
)

# --- CORS 미들웨어 추가 ---
origins = ["*"]
app.add_middleware(
    CORSMiddleware,
    allow_origins=origins,
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# --- AI 모델 및 API 키 설정 ---
try:
    gemini_api_key = os.getenv("GOOGLE_API_KEY")
    if gemini_api_key:
        genai.configure(api_key=gemini_api_key)
    else:
        logging.warning("GOOGLE_API_KEY가 .env 파일에 설정되지 않았습니다. 사진 분석 기능이 작동하지 않을 수 있습니다.")
except Exception as e:
    logging.warning(f"Gemini API 키 설정 실패: {e}")

llm = ChatOpenAI(model="gpt-4o-mini")


# --- 5. 마스터 에이전트 로직 전체 정의 ---

# 5-1. 마스터 에이전트의 State 정의
class MasterAgentState(TypedDict):
    user_input: dict
    route: str
    final_answer: str

# 5-2. 라우터 노드 정의
router_prompt = ChatPromptTemplate.from_template(
    """당신은 사용자의 요청을 분석하여 어떤 담당자에게 전달해야 할지 결정하는 AI 라우터입니다.
    사용자의 요청을 보고, 아래 두 가지 경로 중 가장 적절한 경로 하나만 골라 그 이름만 정확히 답변해주세요.

    [경로 설명]
    1. `meeting_matching`: 사용자가 '새로운 모임'을 만들려고 할 때, 기존에 있던 '유사한 모임'을 추천해주는 경로입니다. (입력에 title, description 등이 포함됩니다)
    2. `hobby_recommendation`: 사용자에게 '새로운 취미' 자체를 추천해주는 경로입니다. (입력에 survey, user_context 등이 포함됩니다)
 
    [사용자 요청]:
    {user_input}

    [판단 결과 (meeting_matching 또는 hobby_recommendation)]:
    """
)
router_chain = router_prompt | llm | StrOutputParser()

def route_request(state: MasterAgentState):
    """사용자의 입력을 보고 어떤 전문가에게 보낼지 결정하는 노드"""
    logging.info("--- ROUTING ---")
    route_decision = router_chain.invoke({"user_input": state['user_input']})
    cleaned_decision = route_decision.strip().lower().replace("'", "").replace('"', '')
    logging.info(f"라우팅 결정: {cleaned_decision}")
    return {"route": cleaned_decision}

# 5-3. 전문가 호출 노드들 정의

# 전문가 1: 모임 매칭 에이전트 (SubGraph) - main_tea.py 코드 기반
def call_meeting_matching_agent(state: MasterAgentState):
    """'모임 매칭 에이전트'를 독립적인 SubGraph로 실행하고 결과를 받아오는 노드"""
    logging.info("--- CALLING: Meeting Matching Agent ---")

    class MeetingAgentState(TypedDict):
        title: str; description: str; time: str; location: str; query: str
        context: List[Document]; answer: str; decision: str; rewrite_count: int

    meeting_llm = ChatOpenAI(model="gpt-4o-mini")
    meeting_index_name = os.getenv("PINECONE_INDEX_NAME_MEETING")
    if not meeting_index_name: raise ValueError("'.env' 파일에 PINECONE_INDEX_NAME_MEETING 변수를 설정해야 합니다.")
    
    embedding_function = OpenAIEmbeddings(model='text-embedding-3-large')
    vector_store = PineconeVectorStore.from_existing_index(index_name=meeting_index_name, embedding=embedding_function)
    retriever = vector_store.as_retriever(
        search_type="similarity_score_threshold",
        search_kwargs={'score_threshold': 0.75, 'k': 2}
    )  

    prepare_query_prompt = ChatPromptTemplate.from_template(
        """당신은 사용자가 입력한 정보를 바탕으로 유사한 다른 정보를 검색하기 위한 최적의 검색어를 만드는 전문가입니다.
        아래 [모임 정보]를 종합하여, 벡터 데이터베이스에서 유사한 모임을 찾기 위한 가장 핵심적인 검색 질문을 한 문장으로 만들어주세요.
        [모임 정보]:
- 제목: {title}
- 설명: {description}
- 시간: {time}
- 장소: {location}"""
    )
    prepare_query_chain = prepare_query_prompt | meeting_llm | StrOutputParser()
    def prepare_query(m_state: MeetingAgentState):
        logging.info("--- (Sub) Preparing Query ---")
        query = prepare_query_chain.invoke({"title": m_state['title'], "description": m_state['description'], "time": m_state.get('time', ''), "location": m_state.get('location', '')})
        logging.info(f"생성된 검색어: {query}")
        return {"query": query}

    def retrieve(m_state: MeetingAgentState):
        logging.info("--- (Sub) Retrieving Context from DB ---")
        context = retriever.invoke(m_state["query"])
        logging.info(f"DB에서 {len(context)}개의 유사 문서를 찾았습니다.")
        return {"context": context}

    generate_prompt = ChatPromptTemplate.from_template(
        """당신은 사용자의 요청을 매우 엄격하게 분석하여 유사한 모임을 추천하는 MOIT 플랫폼의 AI입니다.
        사용자가 만들려는 모임과 **주제, 활동 내용이 명확하게 일치하는** 기존 모임만 추천해야 합니다.

        [사용자 입력 정보]:
        {query}

        [검색된 유사 모임 정보]:
        {context}

        [지시사항]:
        1. [검색된 유사 모임 정보]의 각 항목을 [사용자 입력 정보]와 비교하여, **정말로 관련성이 높다고 판단되는 모임만** 골라냅니다. (예: '축구' 모임을 찾는 사용자에게 '야구' 모임은 추천하지 않습니다.)
        2. 1번에서 골라낸 모임이 있다면, 해당 모임을 기반으로 사용자에게 제안할 추천사를 친절한 말투("~는 어떠세요?")로 작성합니다.
        3. 1번에서 골라낸 모임의 `meeting_id`와 `title`을 추출하여 `recommendations` 배열을 구성합니다. **추천할 모임이 하나뿐이라면, 배열에 하나만 포함합니다.**
        4. 최종 답변을 아래와 같은 JSON 형식으로만 제공해주세요. 다른 텍스트는 절대 포함하지 마세요.

        [JSON 출력 형식 예시]:
        // 추천할 모임이 1개일 경우
        {{
            "summary": "비슷한 축구 모임이 있는데, 참여해 보시는 건 어떠세요?",
            "recommendations": [
                {{ "meeting_id": "축구 모임 ID", "title": "같이 축구하실 분!" }}
            ]
        }}

        // 1번에서 골라낸 모임이 없을 경우 (추천할 만한 모임이 없는 경우)
        {{
            "summary": "",
            "recommendations": []
        }}
        """
    )
    generate_chain = generate_prompt | meeting_llm | StrOutputParser()
    def generate(m_state: MeetingAgentState):
        logging.info("--- (Sub) Generating Final Answer ---")
        context_str = ""
        for i, doc in enumerate(m_state['context']):
            metadata = doc.metadata or {}
            meeting_id = metadata.get('meeting_id', 'N/A')
            title = metadata.get('title', 'N/A')
            context_str += f"모임 {i+1}:\n  - meeting_id: {meeting_id}\n  - title: {title}\n  - content: {doc.page_content}\n\n"
        if not m_state['context']: context_str = "유사한 모임을 찾지 못했습니다."
        answer = generate_chain.invoke({"context": context_str, "query": m_state['query']})
        return {"answer": answer}

    check_helpfulness_prompt = ChatPromptTemplate.from_template(
        """당신은 AI 답변을 평가하는 엄격한 평가관입니다. 주어진 [AI 답변]이 사용자의 [원본 질문] 의도에 대해 유용한 제안을 하는지 평가해주세요.
        다른 설명은 일절 추가하지 말고, 오직 'helpful' 또는 'unhelpful' 둘 중 하나의 단어로만 답변해야 합니다.

        [원본 질문]: {query}
        [AI 답변]: {answer}
        
        [평가 결과 (helpful 또는 unhelpful)]:"""
    )
    check_helpfulness_chain = check_helpfulness_prompt | meeting_llm | StrOutputParser()
    def check_helpfulness(m_state: MeetingAgentState):
        logging.info("--- (Sub) Checking Helpfulness ---")
        raw_result = check_helpfulness_chain.invoke({"query": m_state['query'], "answer": m_state['answer']})
        
        cleaned_result = raw_result.strip().lower().replace('"', '').replace("'", "")
        decision = "helpful" if cleaned_result == "helpful" else "unhelpful"
        
        logging.info(f"답변 유용성 평가 (Raw): {raw_result} -> (Parsed): {decision}")
        return {"decision": decision}

    rewrite_query_prompt = ChatPromptTemplate.from_template(
        """당신은 더 나은 검색 결과를 위해 질문을 재구성하는 프롬프트 엔지니어입니다.
        [원본 질문]은 벡터 검색에서 좋은 결과를 얻지 못했습니다. 원본 질문의 핵심 의도는 유지하되, 완전히 다른 관점에서 접근하거나, 더 구체적인 키워드를 사용하여 관련성 높은 모임을 찾을 수 있는 새로운 검색 질문을 하나만 만들어주세요.
        [원본 질문]: {query}
        [새로운 검색 질문]:"""
    )
    rewrite_query_chain = rewrite_query_prompt | meeting_llm | StrOutputParser()
    def rewrite_query(m_state: MeetingAgentState):
        logging.info("--- (Sub) Rewriting Query ---")
        new_query = rewrite_query_chain.invoke({"query": m_state['query']})
        logging.info(f"재작성된 검색어: {new_query}")
        count = m_state.get('rewrite_count', 0) + 1
        return {"query": new_query, "rewrite_count": count}

    def decide_to_continue(state: MeetingAgentState):
        if state.get("rewrite_count", 0) >= 2: 
            logging.info(f"--- 재시도 횟수({state.get('rewrite_count', 0)}) 초과했기에 루프를 종료합니다. ---")
            return END
        if state.get("decision") == "helpful":
            return END
        return "rewrite_query"

    graph_builder = StateGraph(MeetingAgentState)
    graph_builder.add_node("prepare_query", prepare_query)
    graph_builder.add_node("retrieve", retrieve)
    graph_builder.add_node("generate", generate)
    graph_builder.add_node("check_helpfulness", check_helpfulness)
    graph_builder.add_node("rewrite_query", rewrite_query)
    graph_builder.set_entry_point("prepare_query")
    graph_builder.add_edge("prepare_query", "retrieve")
    graph_builder.add_edge("retrieve", "generate")
    graph_builder.add_edge("generate", "check_helpfulness")
    graph_builder.add_conditional_edges("check_helpfulness", decide_to_continue)
    graph_builder.add_edge("rewrite_query", "retrieve")
    meeting_agent = graph_builder.compile()

    user_input = state['user_input'].get("meeting_info", state['user_input'])
    initial_state = {
        "title": user_input.get("title", ""),
        "description": user_input.get("description", ""),
        "time": user_input.get("time", ""),
        "location": user_input.get("location", ""),
        "rewrite_count": 0
    }
    
    final_result_state = meeting_agent.invoke(initial_state, {"recursion_limit": 15})

    final_decision = final_result_state.get("decision")
    final_answer = final_result_state.get("answer")

    if final_decision == 'helpful':
        logging.info("--- 최종 결정이 'helpful'이므로, 생성된 추천안을 반환합니다. ---")
        return {"final_answer": final_answer}
    else:
        logging.info("--- 최종 결정이 'helpful'이 아니므로, 신규 생성을 유도하기 위해 빈 추천안을 반환합니다. ---")
        empty_recommendation = json.dumps({"summary": "", "recommendations": []})
        return {"final_answer": empty_recommendation}


# 전문가 2: 취미 추천 에이전트 (ReAct) - main_V2/V3의 로직을 이식
@tool
def analyze_photo_tool(image_paths: list[str]) -> str:
    """사용자의 사진(이미지 파일 경로 리스트)을 입력받아, 그 사람의 성향, 분위기, 잠재적 관심사에 대한 텍스트 분석 결과를 반환합니다."""
    from PIL import Image
    try:
        logging.info(f"--- 📸 '사진 분석 전문가'가 작업을 시작합니다. (이미지 {len(image_paths)}개) ---")
        model = genai.GenerativeModel('gemini-2.5-flash')
        photo_analysis_prompt_text = "당신은 사람들의 일상 사진을 보고, 그 사람의 잠재적인 관심사와 성향을 추측하는 심리 분석가입니다. [분석할 사진] 아래 제공된 사진들 [지시사항] 1. 사진들 속 인물, 사물, 배경, 분위기를 종합적으로 분석하세요. 2. 사진 분석 결과를 바탕으로, 이 사람의 성향과 잠재적인 관심사를 3~4개의 핵심 키워드와 함께 설명해주세요. 3. 최종 결과는 다른 AI가 이해하기 쉽도록 간결한 분석 보고서 형식으로 작성해주세요."
        image_parts = [Image.open(path) for path in image_paths]
        response = model.generate_content([photo_analysis_prompt_text] + image_parts)
        logging.info("--- ✅ 사진 분석이 성공적으로 완료되었습니다. ---")
        return response.text
    except Exception as e:
        logging.error(f"사진 분석 중 오류 발생: {e}", exc_info=True)
        return f"오류: 사진 분석 중 문제가 발생했습니다: {e}"

def _normalize(value, min_val, max_val):
    if value is None: return None
    return round((value - min_val) / (max_val - min_val), 4)

@tool
def analyze_survey_tool(survey_json_string: str) -> dict:
    """사용자의 설문 응답(JSON 문자열)을 입력받아, 수치적으로 정규화된 성향 프로필(딕셔너리)을 반환합니다."""
    logging.info("--- 📊 '설문 분석 전문가'가 작업을 시작합니다. ---")
    try:
        responses = json.loads(survey_json_string)
        features = {'FSC': {}, 'PSSR': {}, 'MP': {}, 'DLS': {}}
        features['FSC']['time_availability'] = _normalize(responses.get('1'), 1, 4)
        features['FSC']['financial_budget'] = _normalize(responses.get('2'), 1, 4)
        features['FSC']['energy_level'] = _normalize(responses.get('3'), 1, 5)
        features['FSC']['mobility'] = _normalize(responses.get('4'), 1, 5)
        features['FSC']['has_physical_constraints'] = True if responses.get('5') in [1, 2, 3] else False
        features['FSC']['has_housing_constraints'] = True if responses.get('12') in [2, 3, 4] else False
        features['FSC']['preferred_space'] = 'indoor' if responses.get('6') == 1 else 'outdoor'
        q13 = responses.get('13', 3); q14_r = 6 - responses.get('14', 3); q16 = responses.get('16', 3)
        self_criticism_raw = (q13 + q14_r + q16) / 3
        features['PSSR']['self_criticism_score'] = _normalize(self_criticism_raw, 1, 5)
        q15 = responses.get('15', 3); q18 = responses.get('18', 3); q20 = responses.get('20', 3)
        social_anxiety_raw = (q15 + q18 + q20) / 3
        features['PSSR']['social_anxiety_score'] = _normalize(social_anxiety_raw, 1, 5)
        features['PSSR']['isolation_level'] = _normalize(responses.get('21'), 1, 5)
        features['PSSR']['structure_preference_score'] = _normalize(responses.get('27'), 1, 5)
        features['PSSR']['avoidant_coping_score'] = _normalize(responses.get('29'), 1, 5)
        motivation_map = {1: 'achievement', 2: 'recovery', 3: 'connection', 4: 'vitality'}
        features['MP']['core_motivation'] = motivation_map.get(responses.get('31'))
        features['MP']['value_profile'] = {'knowledge': _normalize(responses.get('33'), 1, 5),'stability': _normalize(responses.get('34'), 1, 5),'relationship': _normalize(responses.get('35'), 1, 5),'health': _normalize(responses.get('36'), 1, 5),'creativity': _normalize(responses.get('37'), 1, 5),'control': _normalize(responses.get('38'), 1, 5),}
        features['MP']['process_orientation_score'] = _normalize(6 - responses.get('41', 3), 1, 5)
        sociality_map = {1: 'solo', 2: 'parallel', 3: 'low_interaction_group', 4: 'high_interaction_group'}
        features['DLS']['preferred_sociality_type'] = sociality_map.get(responses.get('39'))
        group_size_map = {1: 'one_on_one', 2: 'small_group', 3: 'large_group'}
        features['DLS']['preferred_group_size'] = group_size_map.get(responses.get('40'))
        features['DLS']['autonomy_preference_score'] = _normalize(responses.get('42'), 1, 5)
        logging.info("--- ✅ 설문 분석이 성공적으로 완료되었습니다. ---")
        return features
    except Exception as e:
        logging.error(f"설문 분석 중 오류 발생: {e}", exc_info=True)
        return {"error": f"설문 분석 중 오류가 발생했습니다: {e}"}

@tool
def summarize_survey_profile_tool(survey_profile: dict) -> str:
    """'analyze_survey_tool'로부터 받은 정량적인 사용자 프로필(딕셔너리)을 입력받아, 사람이 이해하기 쉬운 텍스트 요약 보고서로 변환합니다."""
    logging.info("--- ✍️ '설문 요약 전문가'가 작업을 시작합니다. ---")
    try:
        summarizer_prompt = ChatPromptTemplate.from_template("당신은 사용자의 성향 분석 데이터를 해석하여, 핵심적인 특징을 요약하는 프로파일러입니다. 아래 <사용자 프로필 데이터>를 보고, 이 사람의 성향을 한두 문단의 자연스러운 문장으로 요약해주세요.\n<사용자 프로필 데이터>\n{profile}\n[데이터 항목 설명] - FSC: 현실적인 제약 조건, PSSR: 심리적 상태, MP: 활동 동기, DLS: 선호하는 사회성\n[요약 예시] '이 사용자는 현재 시간과 예산, 에너지 등 현실적인 제약이 크며, 사회적 불안감이 높아 혼자만의 활동을 통해 회복과 안정을 얻고 싶어하는 성향이 강하게 나타납니다.' 와 같이 간결하게 작성해주세요.")
        summarizer_chain = summarizer_prompt | llm | StrOutputParser()
        summary = summarizer_chain.invoke({"profile": survey_profile})
        logging.info("--- ✅ 설문 요약이 성공적으로 완료되었습니다. ---")
        return summary
    except Exception as e:
        logging.error(f"설문 요약 중 오류 발생: {e}", exc_info=True)
        return f"오류: 설문 요약 중 문제가 발생했습니다: {e}"

hobby_tools = [analyze_survey_tool, summarize_survey_profile_tool, analyze_photo_tool]
hobby_supervisor_prompt = """당신은 사용자의 사진과 설문 결과를 종합하여 맞춤형 취미를 추천하는 AI 큐레이터입니다.
당신의 목표는 아래 전문가(도구)들로부터 받은 분석 보고서를 종합하여, 최종적으로 사용자에게 감동을 주는 맞춤형 추천 메시지를 작성하는 것입니다.

[당신이 지휘할 수 있는 전문가들]
- `analyze_survey_tool`: 사용자의 설문 응답(JSON 문자열)을 받아, 정량적인 성향 프로필(딕셔너리)로 변환합니다.
- `summarize_survey_profile_tool`: `analyze_survey_tool`의 결과(딕셔너리)를 받아, 사람이 이해하기 쉬운 텍스트로 요약합니다.
- `analyze_photo_tool`: 사용자의 사진들을 분석하여 외면적 성향과 활동성을 파악합니다.

다음과 같은 단계로 작업을 **반드시 순서대로** 수행해주세요:

1.  **1단계 (설문 정량 분석):** `analyze_survey_tool`을 사용하여 사용자의 설문 응답을 분석하고, 그 결과로 나온 정량적인 프로필(딕셔너리)을 정확히 확인하세요.

2.  **2단계 (설문 텍스트 요약):** 바로 위 1단계의 실행 결과를 그대로 `summarize_survey_profile_tool`의 `survey_profile` 인자(input)로 전달하여, **사용자의 내면적인 성향(내향성, 회복 추구 등)**이 담긴 텍스트 요약 보고서를 받으세요.

3.  **3단계 (사진 정성 분석):** `analyze_photo_tool`을 사용하여 사용자의 사진을 분석하고, **사용자의 외면적인 활동성(운동, 사회성 등)**이 담긴 텍스트 분석 보고서를 받으세요.

4.  **4단계 (최종 종합 및 추천):**
    - 위 2단계와 3단계에서 얻은 **두 개의 핵심 텍스트 보고서를 나란히 비교 분석**하세요.
    - **[매우 중요]** 두 보고서의 내용이 서로 상반될 경우(예: 설문은 내향적, 사진은 외향적), 이 **차이점을 명확히 인지하고 언급**하며, **두 가지 성향을 모두 아우를 수 있는 균형 잡힌 추천**을 하는 것이 당신의 가장 중요한 임무입니다.
    - 최종적으로 사용자에게 가장 적합한 취미 3가지를 추천해주세요. 각 취미를 추천하는 이유를 두 보고서의 단서를 모두 근거로 들어 설득력 있게 설명해야 합니다.

최종 답변은 반드시 사용자에게 직접 말하는 것처럼, 친절하고 따뜻한 말투의 추천 메시지 형식으로 작성해주세요.
이제 모든 전문가의 분석 보고서가 준비되었습니다.
더 이상 도구를 사용하지 말고, 위 지침에 따라 사용자에게 전달할 최종 추천 메시지를 작성하세요. 최종 답변은 반드시 사용자에게 직접 말하는 것처럼, 친절하고 따뜻한 말투의 추천 메시지 형식으로 작성해주세요.
"""
hobby_prompt = ChatPromptTemplate.from_messages([("system", hobby_supervisor_prompt), MessagesPlaceholder(variable_name="messages")])
hobby_supervisor_agent = create_react_agent(llm, hobby_tools, prompt=hobby_prompt)

def call_multimodal_hobby_agent(state: MasterAgentState):
    """'멀티모달 취미 추천 감독관(ReAct Agent)'을 호출하고 결과를 받아오는 노드"""
    print("--- CALLING: Multimodal Hobby Supervisor Agent ---")
    
    # [★핵심 수정★] main_V2.py의 성공적인 접근 방식을 적용합니다.
    # 1. 사용자 입력에서 'survey'와 'image_paths'를 명확히 분리합니다.
    hobby_info = state["user_input"].get("hobby_info", state["user_input"])
    survey_data = hobby_info.get("survey", {})
    image_paths = hobby_info.get("image_paths", [])

    # 2. 에이전트가 이해하기 쉬운 자연어 지시와 함께 구조화된 데이터를 전달합니다.
    initial_prompt = f"이 사용자의 설문 결과와 사진들을 분석해서 맞춤형 취미를 추천해줘.\n\n- 설문 JSON: {json.dumps(survey_data, ensure_ascii=False)}\n- 사진 경로: {image_paths}"

    input_data = {"messages": [("user", initial_prompt)]}

    final_answer = ""
    for event in hobby_supervisor_agent.stream(input_data, {"recursion_limit": 15}):
        if "messages" in event:
            last_message = event["messages"][-1]
            if isinstance(last_message.content, str) and not last_message.tool_calls:
                final_answer = last_message.content
                
    return {"final_answer": final_answer}


# 5-4. 마스터 에이전트 그래프 조립 및 컴파일
master_graph_builder = StateGraph(MasterAgentState)

master_graph_builder.add_node("router", route_request)
master_graph_builder.add_node("meeting_matcher", call_meeting_matching_agent)
master_graph_builder.add_node("hobby_recommender", call_multimodal_hobby_agent)

master_graph_builder.set_entry_point("router")

master_graph_builder.add_conditional_edges(
    "router", 
    lambda state: state['route'],
    {"meeting_matching": "meeting_matcher", "hobby_recommendation": "hobby_recommender"}
)

master_graph_builder.add_edge("meeting_matcher", END)
master_graph_builder.add_edge("hobby_recommender", END)

master_agent = master_graph_builder.compile()


# --- 6. API 엔드포인트 정의 ---
class UserRequest(BaseModel):
    user_input: dict

@app.post("/agent/invoke")
async def invoke_agent(request: UserRequest):
    try:
        input_data = {"user_input": request.user_input}
        result = master_agent.invoke(input_data, {"recursion_limit": 5}) # 마스터 에이전트에도 안전장치 추가
        return {"final_answer": result.get("final_answer", "오류: 최종 답변을 생성하지 못했습니다.")}
    except Exception as e:
        logging.error(f"Agent 실행 중 심각한 오류 발생: {e}", exc_info=True)
        raise HTTPException(status_code=500, detail=f"AI 에이전트 처리 중 내부 서버 오류가 발생했습니다: {str(e)}")

class NewMeeting(BaseModel):
    meeting_id: str
    title: str
    description: str
    time: str
    location: str

@app.post("/meetings/add")
async def add_meeting_to_pinecone(meeting: NewMeeting):
    try:
        logging.info(f"--- Pinecone에 새로운 모임 추가 시작 (ID: {meeting.meeting_id}) ---")
        meeting_index_name = os.getenv("PINECONE_INDEX_NAME_MEETING")
        if not meeting_index_name: raise ValueError("'.env' 파일에 PINECONE_INDEX_NAME_MEETING이(가) 설정되지 않았습니다.")
        
        embedding_function = OpenAIEmbeddings(model='text-embedding-3-large')
        vector_store = PineconeVectorStore.from_existing_index(index_name=meeting_index_name, embedding=embedding_function)
        
        full_text = f"제목: {meeting.title}\n설명: {meeting.description}\n시간: {meeting.time}\n장소: {meeting.location}"
        metadata = {
            "title": meeting.title, 
            "description": meeting.description, 
            "time": meeting.time, 
            "location": meeting.location,
            "meeting_id": meeting.meeting_id 
        }
        
        vector_store.add_texts(texts=[full_text], metadatas=[metadata], ids=[meeting.meeting_id])
        
        logging.info(f"--- Pinecone에 모임 추가 성공 (ID: {meeting.meeting_id}) ---")
        return {"status": "success", "message": f"모임(ID: {meeting.meeting_id})이 성공적으로 추가되었습니다."}
    except Exception as e:
        logging.error(f"Pinecone 업데이트 중 오류 발생: {e}", exc_info=True)
        raise HTTPException(status_code=500, detail=f"Pinecone에 모임을 추가하는 중 오류가 발생했습니다: {str(e)}")

@app.delete("/meetings/delete/{meeting_id}")
async def delete_meeting_from_pinecone(meeting_id: str):
    try:
        logging.info(f"--- Pinecone에서 모임 삭제 시작 (ID: {meeting_id}) ---")
        meeting_index_name = os.getenv("PINECONE_INDEX_NAME_MEETING")
        if not meeting_index_name: raise ValueError("'.env' 파일에 PINECONE_INDEX_NAME_MEETING이(가) 설정되지 않았습니다.")
        
        embedding_function = OpenAIEmbeddings(model='text-embedding-3-large')
        vector_store = PineconeVectorStore.from_existing_index(index_name=meeting_index_name, embedding=embedding_function)
        
        vector_store.delete(ids=[meeting_id])
        
        logging.info(f"--- Pinecone에서 모임 삭제 성공 (ID: {meeting_id}) ---")
        return {"status": "success", "message": f"모임(ID: {meeting_id})이 성공적으로 삭제되었습니다."}
    except Exception as e:
        logging.error(f"Pinecone 삭제 중 오류 발생: {e}", exc_info=True)
        raise HTTPException(status_code=500, detail=f"Pinecone에서 모임을 삭제하는 중 오류가 발생했습니다.: {str(e)}")